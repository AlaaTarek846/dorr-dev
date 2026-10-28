<?php

namespace Modules\Freelance\Services\Verification;

use App\Services\General\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceDocumentType;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelanceVerificationDocument as Doc;
use Modules\Freelance\Models\FreelanceVerificationRequest as VRequest;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Verifying a freelancer by hand (FL-107 / FL-108): the freelancer uploads the documents their country asks for and
 * submits a request; an admin approves it, rejects it with a reason, or asks for a document to be fixed. Each decision
 * moves the account (FreelancerStatus), is audited and tells the freelancer. An approved document that expires sends a
 * verified freelancer back to fixing it.
 */
class FreelancerVerification
{
    public function __construct(
        private readonly FreelanceDocumentRequirements $requirements,
        private readonly FreelancerGate $gate,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- the freelancer

    /** Everything the verification screen needs: what is required, what is uploaded, and whether it can be sent. */
    public function overview(Freelancer $freelancer): array
    {
        $current = $this->currentDocuments($freelancer);
        $required = $this->requirements->for($freelancer);
        $rows = $required->map(fn (FreelanceDocumentType $t) => [
            'type' => $this->presentType($t),
            'document' => ($doc = $current->get($t->id)) === null ? null : $this->presentDocument($doc),
            'satisfied' => $doc !== null && $this->counts($doc),
        ])->values();
        $last = VRequest::query()->where('freelancer_id', $freelancer->id)->latest('id')->first();

        return [
            'status' => $freelancer->status?->value,
            ...$this->gate->summary($freelancer),
            'needs_residency' => $this->requirements->needsResidency($freelancer),
            'required' => $rows,
            // Optional documents the freelancer added beyond what is required.
            'others' => $current->reject(fn (Doc $d) => $required->contains('id', $d->document_type_id))->map(fn (Doc $d) => $this->presentDocument($d))->values(),
            'can_submit' => ($blockers = $this->submitBlockers($freelancer)) === [],
            'submit_blockers' => $blockers,
            'last_request' => $last === null ? null : ['uuid' => $last->uuid, 'status' => $last->status, 'reason' => $last->reason, 'submitted_at' => $last->submitted_at?->toIso8601String(), 'decided_at' => $last->decided_at?->toIso8601String()],
        ];
    }

    /**
     * Uploads (or replaces) the freelancer's document of a type. Open while nothing is under review; a draft of the same type
     * is replaced, an older reviewed one stays as history.
     *
     * @param  array{number?: ?string, expires_on?: ?string}  $data
     */
    public function upload(Freelancer $freelancer, int $typeId, array $data, UploadedFile $front, ?UploadedFile $back): Doc
    {
        $this->assertEditable($freelancer);
        $type = FreelanceDocumentType::query()->where('status', true)->find($typeId) ?? throw new FreelanceException('verification_document_invalid', 422);

        $missing = array_values(array_filter([
            $type->has_number && blank($data['number'] ?? null) ? 'number' : null,
            $type->has_expiry && blank($data['expires_on'] ?? null) ? 'expires_on' : null,
            $type->needs_back_side && $back === null ? 'back' : null,
        ]));
        if ($missing !== []) {
            throw new FreelanceException('verification_document_incomplete', 422, ['fields' => implode(', ', $missing)], ['fields' => $missing]);
        }

        return DB::transaction(function () use ($freelancer, $type, $data, $front, $back) {
            Doc::query()->where('freelancer_id', $freelancer->id)->where('document_type_id', $type->id)->where('status', Doc::DRAFT)->get()->each(fn (Doc $d) => $this->wipe($d));

            $doc = Doc::query()->create([
                'freelancer_id' => $freelancer->id,
                'document_type_id' => $type->id,
                'number' => $type->has_number ? $data['number'] : null,
                'expires_on' => $type->has_expiry ? $data['expires_on'] : null,
                'status' => Doc::DRAFT,
            ]);
            $doc->setFile('front', $front);
            if ($back !== null && $type->needs_back_side) {
                $doc->setFile('back', $back);
            }

            return $doc->load('documentType.translations');
        });
    }

    /** Only a document not sent yet can be taken back. */
    public function deleteDraft(Freelancer $freelancer, Doc $doc): void
    {
        if ($doc->status !== Doc::DRAFT) {
            throw new FreelanceException('verification_document_locked', 409);
        }
        $this->wipe($doc);
    }

    public function submit(Freelancer $freelancer): VRequest
    {
        $blockers = $this->submitBlockers($freelancer);
        if ($blockers !== []) {
            $code = in_array('not_allowed', $blockers, true) ? 'verification_not_allowed' : (in_array('residency', $blockers, true) ? 'verification_residency_required' : 'verification_incomplete');
            throw new FreelanceException($code, $code === 'verification_not_allowed' ? 409 : 422, [], ['blockers' => $blockers, 'step' => $this->gate->step($freelancer)]);
        }

        $request = DB::transaction(function () use ($freelancer) {
            $request = VRequest::query()->create(['freelancer_id' => $freelancer->id, 'status' => VRequest::SUBMITTED, 'submitted_at' => now()]);
            Doc::query()->where('freelancer_id', $freelancer->id)->where('status', Doc::DRAFT)->update(['status' => Doc::PENDING, 'request_id' => $request->id]);
            $freelancer->forceFill(['status' => FreelancerStatus::VerificationSubmitted, 'status_reason' => null])->save();

            return $request;
        });
        $this->audit->record('freelancer.verification_submitted', $freelancer, null, ['request' => $request->uuid]);

        return $request;
    }

    /** @return list<string> why the request can't be sent yet: not_allowed, residency, missing:{code}, expired:{code} */
    public function submitBlockers(Freelancer $freelancer): array
    {
        if (! $freelancer->status?->canSubmitVerification()) {
            return ['not_allowed'];
        }
        if ($this->requirements->needsResidency($freelancer)) {
            return ['residency'];
        }
        $current = $this->currentDocuments($freelancer);
        $blockers = [];
        foreach ($this->requirements->for($freelancer) as $type) {
            $doc = $current->get($type->id);
            if ($doc === null || in_array($doc->status, [Doc::REJECTED, Doc::EXPIRED], true)) {
                $blockers[] = 'missing:'.$type->code;
            } elseif ($doc->isExpired()) {
                $blockers[] = 'expired:'.$type->code;
            }
        }

        return $blockers;
    }

    // -------------------------------------------------------------- the admin

    /**
     * The admin's decision on a request. `approve` takes every document still pending (unless rejected one by one, which
     * then fails); `reject` and `changes` need a reason the freelancer sees; documents decided one by one carry their own.
     *
     * @param  'approve'|'reject'|'changes'  $decision
     * @param  array<int, array{status: 'approved'|'rejected', reason?: ?string}>  $documents  by document id
     */
    public function decide(VRequest $request, string $decision, ?string $reason, array $documents, int $adminId): VRequest
    {
        if ($request->status !== VRequest::SUBMITTED) {
            throw new FreelanceException('verification_already_decided', 409);
        }
        if ($decision !== 'approve' && blank($reason)) {
            throw new FreelanceException('verification_reason_required', 422);
        }
        $freelancer = $request->freelancer;

        DB::transaction(function () use ($request, $decision, $reason, $documents, $adminId, $freelancer) {
            $pending = Doc::query()->where('freelancer_id', $freelancer->id)->where('status', Doc::PENDING)->get()->keyBy('id');
            foreach ($documents as $id => $choice) {
                $doc = $pending->get((int) $id);
                if ($doc === null) {
                    continue;
                }
                if ($choice['status'] === Doc::REJECTED && blank($choice['reason'] ?? null)) {
                    throw new FreelanceException('verification_reason_required', 422);
                }
                $this->review($doc, $choice['status'], $choice['reason'] ?? null, $adminId);
            }

            // What is still pending follows the request's decision.
            foreach ($pending->filter(fn (Doc $d) => $d->fresh()->status === Doc::PENDING) as $doc) {
                $this->review($doc, $decision === 'approve' ? Doc::APPROVED : Doc::REJECTED, $decision === 'approve' ? null : $reason, $adminId);
            }

            if ($decision === 'approve') {
                $blockers = $this->approvedBlockers($freelancer);
                if ($blockers !== []) {
                    throw new FreelanceException('verification_incomplete', 422, [], ['blockers' => $blockers]);
                }
            }

            $request->forceFill([
                'status' => ['approve' => VRequest::APPROVED, 'reject' => VRequest::REJECTED, 'changes' => VRequest::CHANGES_REQUESTED][$decision],
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
                'reason' => $decision === 'approve' ? null : $reason,
            ])->save();

            // An account suspended or blocked meanwhile keeps that; the decision is still on record.
            if ($freelancer->status === FreelancerStatus::VerificationSubmitted) {
                $freelancer->forceFill(match ($decision) {
                    'approve' => ['status' => FreelancerStatus::Verified, 'status_reason' => null, 'verified_at' => now()],
                    'reject' => ['status' => FreelancerStatus::VerificationRejected, 'status_reason' => $reason],
                    default => ['status' => FreelancerStatus::ChangesRequested, 'status_reason' => $reason],
                })->save();
            }
        });

        $this->audit->record('freelancer.verification_'.$decision, $freelancer, null, ['request' => $request->uuid, 'reason' => $reason, 'admin' => $adminId]);
        match ($decision) {
            'approve' => $this->notifier->notify($freelancer, 'verification.approved', [], ['request' => $request->uuid]),
            'reject' => $this->notifier->notify($freelancer, 'verification.rejected', ['reason' => (string) $reason], ['request' => $request->uuid]),
            default => $this->notifier->notify($freelancer, 'verification.changes_requested', ['reason' => (string) $reason], ['request' => $request->uuid]),
        };

        return $request->refresh();
    }

    /**
     * The daily sweep: an approved document past its date expires, and a verified freelancer who now misses a required one
     * goes back to fixing it (they keep their profile; only the work waits).
     */
    public function expireDocuments(): int
    {
        $expired = Doc::query()->where('status', Doc::APPROVED)->whereNotNull('expires_on')->where('expires_on', '<', today())->get();
        foreach ($expired->groupBy('freelancer_id') as $freelancerId => $docs) {
            Doc::query()->whereIn('id', $docs->pluck('id'))->update(['status' => Doc::EXPIRED]);
            $freelancer = Freelancer::query()->find($freelancerId);
            if ($freelancer !== null && $freelancer->status === FreelancerStatus::Verified && $this->approvedBlockers($freelancer) !== []) {
                $reason = __('freelance.verification.document_expired', [], $freelancer->locale ?? 'ar');
                $freelancer->forceFill(['status' => FreelancerStatus::ChangesRequested, 'status_reason' => $reason])->save();
                $this->notifier->notify($freelancer, 'verification.changes_requested', ['reason' => $reason]);
            }
        }

        return $expired->count();
    }

    // -------------------------------------------------------------- shape (shared with the admin screen)

    /** @return Collection<int, Doc> the latest document of each type (an approved one that was replaced is history) */
    public function currentDocuments(Freelancer $freelancer): Collection
    {
        return Doc::query()->with(['documentType.translations', 'media'])
            ->where('freelancer_id', $freelancer->id)->where('status', '!=', Doc::REPLACED)
            ->orderByDesc('id')->get()->unique('document_type_id')->keyBy('document_type_id');
    }

    /** @return array<string, mixed> */
    public function presentType(FreelanceDocumentType $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->translatedName(), 'instructions' => $t->translated('instructions'), 'has_number' => $t->has_number, 'has_expiry' => $t->has_expiry, 'needs_back_side' => $t->needs_back_side];
    }

    /** @return array<string, mixed> files are given as paths of a signed-in endpoint, never as links */
    public function presentDocument(Doc $d, string $filesBase = 'verification/documents'): array
    {
        return [
            'id' => $d->id,
            'type' => $d->documentType === null ? null : ['id' => $d->documentType->id, 'code' => $d->documentType->code, 'name' => $d->documentType->translatedName()],
            'number' => $d->number,
            'expires_on' => $d->expires_on?->toDateString(),
            'is_expired' => $d->isExpired(),
            'status' => $d->status,
            'rejection_reason' => $d->rejection_reason,
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            'files' => collect(Doc::SIDES)->mapWithKeys(fn (string $side) => [$side => ($m = $d->file($side)) === null ? null : ['name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size, 'path' => "{$filesBase}/{$d->id}/{$side}"]])->all(),
        ];
    }

    // -------------------------------------------------------------- internals

    private function review(Doc $doc, string $status, ?string $reason, int $adminId): void
    {
        if ($status === Doc::APPROVED) {
            // The newly approved one takes over from the old approved one of the same type.
            Doc::query()->where('freelancer_id', $doc->freelancer_id)->where('document_type_id', $doc->document_type_id)
                ->where('status', Doc::APPROVED)->whereKeyNot($doc->id)->update(['status' => Doc::REPLACED]);
        }
        $doc->forceFill(['status' => $status, 'rejection_reason' => $status === Doc::REJECTED ? $reason : null, 'reviewed_by_admin_id' => $adminId, 'reviewed_at' => now()])->save();
    }

    /** @return list<string> required types without a valid approved document */
    private function approvedBlockers(Freelancer $freelancer): array
    {
        $current = $this->currentDocuments($freelancer);

        return $this->requirements->for($freelancer)
            ->reject(fn (FreelanceDocumentType $t) => ($d = $current->get($t->id)) !== null && $d->status === Doc::APPROVED && ! $d->isExpired())
            ->map(fn (FreelanceDocumentType $t) => 'missing:'.$t->code)->values()->all();
    }

    /** A document counts toward a request when it is waiting, approved or about to be sent, and not past its date. */
    private function counts(Doc $doc): bool
    {
        return in_array($doc->status, [Doc::DRAFT, Doc::PENDING, Doc::APPROVED], true) && ! $doc->isExpired();
    }

    private function assertEditable(Freelancer $freelancer): void
    {
        if (in_array($freelancer->status, [FreelancerStatus::VerificationSubmitted, FreelancerStatus::Blocked], true)) {
            throw new FreelanceException('verification_document_locked', 409);
        }
    }

    private function wipe(Doc $doc): void
    {
        foreach (Doc::SIDES as $side) {
            $doc->clearMediaCollection($side);
        }
        $doc->delete();
    }
}
