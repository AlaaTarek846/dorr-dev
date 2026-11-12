<?php

namespace Modules\Chat\Services\Verification;

use App\Services\General\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Admin;
use Modules\Chat\Enums\VerificationRequestStatus as Status;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Models\Verification\ChatVerificationRequest as VRequest;
use Modules\Chat\Models\Verification\ChatVerificationRequestDocument as Doc;
use Modules\Chat\Models\Verification\ChatVerificationRequestTransition;
use Modules\Chat\Models\Verification\ChatVerificationSetting;
use Modules\Chat\Services\ChatFeatures;
use Modules\User\Models\User;

/**
 * Verification requests (docs/verification_tasks.md, batch 1): what the account does — send, complete,
 * cancel — and what the staff decide — review, check the identity, ask for more, approve, reject, move a
 * deadline — plus the sweep that warns before a deadline and puts a request on hold once it passes.
 *
 * Every status move goes through transition(): it checks VerificationRequestStatus::transitions(), keeps
 * one open request per account (`open_key`), and writes the move to the request's trail. A staff decision
 * is also written to the audit log with its reason, and every step notifies both sides (VerificationNotifier).
 */
class VerificationRequestService
{
    public function __construct(
        private readonly ChatFeatures $features,
        private readonly VerificationNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly VerificationBadgeService $badges,
    ) {}

    // ---------------------------------------------------------------- the account

    /**
     * AV-011 / AV-012: a new request with its documents. `$files` = [document_type_id => ['front' => file, 'back' => file?]].
     *
     * @param  array{track: string, full_name: string, contact_phone?: ?string, contact_email?: ?string, notes?: ?string}  $data
     * @param  array<int|string, array<string, UploadedFile>>  $files
     */
    public function submit(User $me, array $data, array $files): VRequest
    {
        $track = VerificationTrack::from($data['track']);
        if (! $this->features->allows($me, 'verification_'.$track->value)) {
            throw new ChatException('verification_track_unavailable', 403);
        }

        $types = ChatVerificationDocumentType::query()->with('translations')->forTrack($track)->get();
        $this->assertDocuments($types, $files, $types->where('is_required', true)->pluck('id')->all());

        $settings = ChatVerificationSetting::current();

        try {
            $request = DB::transaction(function () use ($me, $data, $track, $types, $files, $settings) {
                // One open request per account: checked here, and held by the unique `open_key` if two arrive at once.
                $open = VRequest::query()->where('open_key', $me->id)->lockForUpdate()->first();
                if ($open !== null) {
                    throw new ChatException('verification_request_open', 409, [], ['request_uuid' => $open->uuid]);
                }

                $request = VRequest::query()->create([
                    'user_id' => $me->id,
                    'track' => $track,
                    'status' => Status::New,
                    'open_key' => $me->id,
                    'full_name' => $data['full_name'],
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'contact_email' => $data['contact_email'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'submitted_at' => now(),
                    'deadline_at' => now()->addDays($settings->review_deadline_days),
                    'deadline_owner' => 'admin',
                ]);
                $this->trail($request, null, Status::New, $me);
                $this->storeDocuments($request, $types, $files);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ChatException('verification_request_open', 409);
        }

        $this->notifier->notify('submitted', $me, $request);

        return $request;
    }

    /** The account's latest request (open or not), or null. */
    public function current(User $me): ?VRequest
    {
        return VRequest::query()->where('user_id', $me->id)->latest('id')->first();
    }

    /**
     * AV-014: the account sends what it was asked for; the request goes back to the reviewers with a fresh deadline.
     *
     * @param  array{full_name?: string, contact_phone?: ?string, contact_email?: ?string, note?: ?string}  $data
     * @param  array<int|string, array<string, UploadedFile>>  $files
     */
    public function complete(User $me, VRequest $request, array $data, array $files): VRequest
    {
        $this->assertOwn($me, $request);
        $settings = ChatVerificationSetting::current();

        DB::transaction(function () use ($me, $request, $data, $files, $settings) {
            $request = $this->locked($request);
            if ($request->status !== Status::NeedsCompletion) {
                throw new ChatException('verification_status_invalid', 409);
            }

            $types = ChatVerificationDocumentType::query()->with('translations')->withTrashed()
                ->where(fn ($q) => $q->where('status', true)->whereJsonContains('tracks', $request->track->value))
                ->orWhereIn('id', $request->completion_document_type_ids ?? [])->get();
            $this->assertDocuments($types, $files, $request->completion_document_type_ids ?? []);

            $contact = array_filter(array_intersect_key($data, array_flip(['full_name', 'contact_phone', 'contact_email'])), fn ($v) => $v !== null);
            if ($files === [] && $contact === [] && blank($data['note'] ?? null)) {
                throw new ChatException('verification_completion_empty', 422);
            }

            $this->storeDocuments($request, $types, $files);
            $request->fill($contact);
            $this->transition($request, Status::UnderReview, $me, $data['note'] ?? null, [
                'completion_reason' => null,
                'completion_document_type_ids' => null,
                'deadline_at' => now()->addDays($settings->review_deadline_days),
                'deadline_owner' => 'admin',
            ]);
        });

        $this->notifier->notify('documents_uploaded', $me, $request);

        return $request->refresh();
    }

    /** AV-019: the account withdraws its request before the final decision. Refunds follow AV-047 (batch 4). */
    public function cancelByAccount(User $me, VRequest $request, ?string $reason): VRequest
    {
        $this->assertOwn($me, $request);

        DB::transaction(function () use ($me, $request, $reason) {
            $request = $this->locked($request);
            if ($request->status === Status::FinalReview) {
                throw new ChatException('verification_cancel_too_late', 409);
            }
            $this->transition($request, Status::Cancelled, $me, $reason);
        });

        $this->notifier->notify('cancelled', $me, $request);

        return $request->refresh();
    }

    // ---------------------------------------------------------------- the staff

    /** A reviewer picks the request up. */
    public function startReview(Admin $admin, VRequest $request): VRequest
    {
        return $this->decide($admin, $request, 'review_started', function (VRequest $r) use ($admin) {
            $this->transition($r, Status::UnderReview, $admin);
        });
    }

    /** AV-015: one document accepted or turned down (a turned-down one needs a note the account will read). */
    public function reviewDocument(Admin $admin, VRequest $request, int $documentId, bool $accepted, ?string $note): VRequest
    {
        if (! $accepted && blank($note)) {
            throw new ChatException('verification_reason_required', 422);
        }

        return $this->decide($admin, $request, 'document_reviewed', function (VRequest $r) use ($admin, $documentId, $accepted, $note) {
            if (! in_array($r->status, [Status::New, Status::UnderReview], true)) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $doc = $r->documents()->whereKey($documentId)->where('status', '!=', Doc::REPLACED)->first()
                ?? throw new ChatException('verification_document_not_found', 404);
            $doc->forceFill([
                'status' => $accepted ? Doc::ACCEPTED : Doc::REJECTED,
                'review_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return ['document_id' => $doc->id, 'status' => $doc->status];
        }, $note);
    }

    /** AV-015 / REQ-01-04: the first identity check passed — every required document of the track accepted. */
    public function checkIdentity(Admin $admin, VRequest $request): VRequest
    {
        $request = $this->decide($admin, $request, 'identity_checked', function (VRequest $r) use ($admin) {
            if ($r->status !== Status::UnderReview) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $accepted = $r->documents()->where('status', Doc::ACCEPTED)->pluck('document_type_id')->all();
            $missing = ChatVerificationDocumentType::query()->forTrack($r->track)->where('is_required', true)
                ->whereNotIn('id', $accepted)->pluck('id')->all();
            if ($missing !== []) {
                throw new ChatException('verification_documents_not_accepted', 422, [], ['document_type_ids' => $missing]);
            }
            $r->forceFill(['identity_checked_at' => now(), 'identity_checked_by' => $admin->id])->save();
        });
        $this->notifier->notify('identity_checked', $request->user, $request);
        // The track (or this request) may turn the badge on right here (AV-021).
        $this->badges->stepReached($request, 'identity_checked', $admin);

        return $request;
    }

    /**
     * AV-016: the account is asked for more — the reason it reads, and which documents to upload again.
     *
     * @param  list<int>  $documentTypeIds
     */
    public function requestCompletion(Admin $admin, VRequest $request, string $reason, array $documentTypeIds): VRequest
    {
        $settings = ChatVerificationSetting::current();
        $request = $this->decide($admin, $request, 'completion_requested', function (VRequest $r) use ($admin, $reason, $documentTypeIds, $settings) {
            $this->transition($r, Status::NeedsCompletion, $admin, $reason, [
                'completion_reason' => $reason,
                'completion_document_type_ids' => array_values(array_unique(array_map('intval', $documentTypeIds))) ?: null,
                'deadline_at' => now()->addDays($settings->completion_deadline_days),
                'deadline_owner' => 'user',
            ]);
        }, $reason);
        $this->notifier->notify('completion_requested', $request->user, $request, ['reason' => $reason]);

        return $request;
    }

    /** AV-016: approved (an approver, never the reviewer — REQ-09-03), only after the identity check. */
    public function approve(Admin $admin, VRequest $request, ?string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'approved', function (VRequest $r) use ($admin, $reason) {
            if ($r->status !== Status::UnderReview) {
                throw new ChatException('verification_status_invalid', 409);
            }
            if ($r->identity_checked_at === null) {
                throw new ChatException('verification_identity_not_checked', 422);
            }
            // The paid track goes on to payment (batch 4), the celebrity track to its agreement (batch 3) — each
            // sets its own deadline then.
            $this->transition($r, Status::PreliminaryApproved, $admin, $reason, [
                'decision_reason' => $reason, 'decided_by' => $admin->id, 'decided_at' => now(),
                'deadline_at' => null, 'deadline_owner' => null, 'deadline_warned_at' => null,
            ]);
        }, $reason);
        $this->notifier->notify('preliminary_approved', $request->user, $request);
        $this->badges->stepReached($request, 'approved', $admin);

        return $request;
    }

    /** AV-016: turned down, with the reason the account reads. */
    public function reject(Admin $admin, VRequest $request, string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'rejected', function (VRequest $r) use ($admin, $reason) {
            $this->transition($r, Status::Rejected, $admin, $reason, [
                'decision_reason' => $reason, 'decided_by' => $admin->id, 'decided_at' => now(),
            ]);
        }, $reason);
        $this->notifier->notify('rejected', $request->user, $request, ['reason' => $reason]);

        return $request;
    }

    /** AV-017: a new deadline (later than now), with the reason. A paused deadline gets its new length instead. */
    public function extendDeadline(Admin $admin, VRequest $request, CarbonInterface $until, string $reason): VRequest
    {
        if ($until->lte(now())) {
            throw new ChatException('verification_deadline_invalid', 422);
        }
        $request = $this->decide($admin, $request, 'deadline_extended', function (VRequest $r) use ($until) {
            if (! $r->isOpen() || $r->status === Status::Suspended || $r->deadline_owner === null) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $r->forceFill($r->deadline_paused_at !== null
                ? ['deadline_remaining_seconds' => (int) now()->diffInSeconds($until, true)]
                : ['deadline_at' => $until, 'deadline_warned_at' => null])->save();

            return ['deadline_at' => $until->toIso8601String()];
        }, $reason);
        $this->notifier->notify('deadline_changed', $request->user, $request, ['date' => $this->when($request->user, $until)]);

        return $request;
    }

    /** AV-017: the clock stops (waiting on something outside the request), keeping what was left. */
    public function pauseDeadline(Admin $admin, VRequest $request, string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'deadline_paused', function (VRequest $r) {
            if (! $r->isOpen() || $r->deadline_at === null || $r->deadline_paused_at !== null) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $r->forceFill([
                'deadline_paused_at' => now(),
                'deadline_remaining_seconds' => max(0, (int) now()->diffInSeconds($r->deadline_at, false)),
                'deadline_at' => null,
            ])->save();
        }, $reason);
        $this->notifier->notify('deadline_paused', $request->user, $request);

        return $request;
    }

    /** AV-017: the clock runs again with what was left when it stopped. */
    public function resumeDeadline(Admin $admin, VRequest $request, ?string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'deadline_resumed', function (VRequest $r) {
            if (! $r->isOpen() || $r->deadline_paused_at === null) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $until = now()->addSeconds(max(3600, (int) $r->deadline_remaining_seconds));
            $r->forceFill(['deadline_at' => $until, 'deadline_paused_at' => null, 'deadline_remaining_seconds' => null, 'deadline_warned_at' => null])->save();

            return ['deadline_at' => $until->toIso8601String()];
        }, $reason);
        $this->notifier->notify('deadline_changed', $request->user, $request, ['date' => $this->when($request->user, $request->deadline_at)]);

        return $request;
    }

    /** AV-018: a request on hold goes back where it was, with a new deadline. */
    public function resumeSuspended(Admin $admin, VRequest $request, CarbonInterface $until, string $reason): VRequest
    {
        if ($until->lte(now())) {
            throw new ChatException('verification_deadline_invalid', 422);
        }
        $request = $this->decide($admin, $request, 'resumed', function (VRequest $r) use ($admin, $until, $reason) {
            if ($r->status !== Status::Suspended || $r->suspended_from === null) {
                throw new ChatException('verification_status_invalid', 409);
            }
            $back = $r->suspended_from;
            $this->transition($r, $back, $admin, $reason, [
                'suspended_from' => null,
                'deadline_at' => $until,
                'deadline_owner' => $back === Status::NeedsCompletion ? 'user' : 'admin',
                'deadline_warned_at' => null,
            ]);
        }, $reason);
        $this->notifier->notify('deadline_changed', $request->user, $request, ['date' => $this->when($request->user, $until)]);

        return $request;
    }

    /** AV-018: a request on hold is closed because its time ran out. */
    public function closeExpired(Admin $admin, VRequest $request, string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'expired', function (VRequest $r) use ($admin, $reason) {
            $this->transition($r, Status::Expired, $admin, $reason);
        }, $reason);
        $this->notifier->notify('expired', $request->user, $request, ['reason' => $reason]);

        return $request;
    }

    /** AV-018: the staff cancel a request (from hold, or any open status), with the reason. */
    public function cancelByStaff(Admin $admin, VRequest $request, string $reason): VRequest
    {
        $request = $this->decide($admin, $request, 'cancelled', function (VRequest $r) use ($admin, $reason) {
            $this->transition($r, Status::Cancelled, $admin, $reason, ['decision_reason' => $reason]);
        }, $reason);
        $this->notifier->notify('cancelled', $request->user, $request);

        return $request;
    }

    // ---------------------------------------------------------------- the clock (scheduled)

    /**
     * AV-018: warns once before a deadline, and puts a request on hold once its deadline passed (both sides told).
     *
     * @return array{warned: int, suspended: int}
     */
    public function sweepDeadlines(): array
    {
        $settings = ChatVerificationSetting::current();
        $warned = 0;
        $suspended = 0;

        VRequest::query()->with('user')->whereIn('status', Status::openValues())->where('status', '!=', Status::Suspended->value)
            ->whereNotNull('deadline_at')->whereNull('deadline_warned_at')
            ->where('deadline_at', '>', now())->where('deadline_at', '<=', now()->addHours($settings->deadline_warning_hours))
            ->each(function (VRequest $r) use (&$warned) {
                $r->forceFill(['deadline_warned_at' => now()])->save();
                $this->notifier->notify('deadline_near', $r->user, $r, ['date' => $this->when($r->user, $r->deadline_at)]);
                $warned++;
            });

        VRequest::query()->whereIn('status', Status::openValues())->where('status', '!=', Status::Suspended->value)
            ->whereNotNull('deadline_at')->where('deadline_at', '<=', now())
            ->pluck('id')->each(function (int $id) use (&$suspended) {
                $request = DB::transaction(function () use ($id) {
                    $r = VRequest::query()->lockForUpdate()->find($id);
                    if ($r === null || $r->deadline_at === null || $r->deadline_at->isFuture() || ! $r->isOpen() || $r->status === Status::Suspended) {
                        return null;
                    }
                    $from = $r->status;
                    $this->transition($r, Status::Suspended, null, 'deadline_passed', ['suspended_from' => $from, 'deadline_at' => null]);
                    $this->audit->record('chat_verification.request_suspended', $r, ['status' => $from->value], ['status' => Status::Suspended->value], null, 'deadline_passed');

                    return $r;
                });
                if ($request !== null) {
                    $this->notifier->notify('deadline_passed', $request->user, $request);
                    $suspended++;
                }
            });

        return ['warned' => $warned, 'suspended' => $suspended];
    }

    // ---------------------------------------------------------------- internals

    /**
     * A staff decision on a locked request, written to the audit log with the status before and after, and the reason.
     *
     * @param  \Closure(VRequest): (array<string, mixed>|null|void)  $work
     */
    private function decide(Admin $admin, VRequest $request, string $action, \Closure $work, ?string $reason = null): VRequest
    {
        return DB::transaction(function () use ($admin, $request, $action, $work, $reason) {
            $r = $this->locked($request);
            $before = ['status' => $r->status->value];
            $extra = $work($r) ?? [];
            $this->audit->record('chat_verification.request_'.$action, $r, $before, ['status' => $r->status->value] + $extra, $admin, $reason);

            return $r->refresh()->load('user');
        });
    }

    /**
     * Moves the request, if the move is allowed. Closing frees the account for a new request.
     *
     * @param  array<string, mixed>  $attributes  saved with the move
     */
    private function transition(VRequest $request, Status $to, ?Model $actor, ?string $reason = null, array $attributes = []): void
    {
        $from = $request->status;
        if (! $from->canMoveTo($to)) {
            throw new ChatException('verification_status_invalid', 409, [], ['from' => $from->value, 'to' => $to->value]);
        }

        $request->forceFill($attributes + ['status' => $to]);
        if ($to->isClosed()) {
            $request->forceFill(['open_key' => null, 'closed_at' => now(), 'deadline_at' => null, 'deadline_owner' => null, 'deadline_paused_at' => null, 'deadline_remaining_seconds' => null]);
        }
        $request->save();
        $this->trail($request, $from, $to, $actor, $reason);

        // Closed without completing: a temporary badge it gave goes (AT-04).
        if ($to->isClosed()) {
            $this->badges->requestClosed($request);
        }
    }

    private function trail(VRequest $request, ?Status $from, Status $to, ?Model $actor, ?string $reason = null): void
    {
        ChatVerificationRequestTransition::query()->create([
            'request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => match (true) {
                $actor instanceof Admin => 'admin',
                $actor instanceof User => 'user',
                default => 'system',
            },
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
        ]);
    }

    /**
     * Every required type has its front (and its back when needed); files of other types are refused.
     *
     * @param  Collection<int, ChatVerificationDocumentType>  $types  the types this upload may carry
     * @param  array<int|string, array<string, UploadedFile>>  $files
     * @param  list<int>  $required
     */
    private function assertDocuments(Collection $types, array $files, array $required): void
    {
        $byId = $types->keyBy('id');
        $unknown = array_values(array_diff(array_map('intval', array_keys($files)), $byId->keys()->all()));
        if ($unknown !== []) {
            throw new ChatException('verification_document_not_found', 422, [], ['document_type_ids' => $unknown]);
        }

        $missing = [];
        foreach (array_unique(array_map('intval', $required)) as $id) {
            $type = $byId->get($id);
            $sent = $files[$id] ?? [];
            if (! isset($sent['front']) || ($type?->needs_back_side && ! isset($sent['back']))) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            throw new ChatException('verification_documents_missing', 422, [], ['document_type_ids' => $missing]);
        }
    }

    /**
     * @param  Collection<int, ChatVerificationDocumentType>  $types
     * @param  array<int|string, array<string, UploadedFile>>  $files
     */
    private function storeDocuments(VRequest $request, Collection $types, array $files): void
    {
        $byId = $types->keyBy('id');
        foreach ($files as $typeId => $sides) {
            $type = $byId->get((int) $typeId);
            if ($type === null || ! isset($sides['front'])) {
                continue;
            }
            // A newer upload of the same type takes over from the earlier one.
            $request->documents()->where('document_type_id', $type->id)->where('status', '!=', Doc::REPLACED)->update(['status' => Doc::REPLACED]);
            $doc = $request->documents()->create(['document_type_id' => $type->id, 'status' => Doc::PENDING]);
            $doc->setFile('front', $sides['front']);
            if ($type->needs_back_side && isset($sides['back'])) {
                $doc->setFile('back', $sides['back']);
            }
        }
    }

    private function locked(VRequest $request): VRequest
    {
        return VRequest::query()->lockForUpdate()->findOrFail($request->id);
    }

    private function assertOwn(User $me, VRequest $request): void
    {
        if ($request->user_id !== $me->id) {
            throw new ChatException('verification_request_not_found', 404);
        }
    }

    /** A date as the account reads it: in its own time zone. */
    private function when(?User $user, ?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        return $at->copy()->setTimezone($user?->timezone ?: config('app.timezone'))->format('Y-m-d H:i');
    }
}
