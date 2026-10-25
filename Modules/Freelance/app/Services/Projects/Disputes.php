<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Models\FreelanceDisputeEvidence as Evidence;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Disputes on a part of the work (FL-704 / FL-507 / FL-705).
 *
 * Either side opens one on a part with money held (funded, in progress, delivered or being changed): the part becomes `disputed`, which
 * freezes it — no approving (by hand or by the clock), no changes, no ending — and its money stays held. Both sides add what they want
 * to show (a text and a file) within the response days. A member of staff decides: everything back to the client (`refund`), everything
 * to the freelancer (`release`), or a split (the freelancer's share in basis points). Nothing moves at once: the decision waits for the
 * appeal days (both from the policy frozen in the agreement, `disputes.response_days` / `disputes.appeal_days`). Either side may appeal
 * once in that time; another member of staff then decides, and that decision is final and runs at once. When it runs, the money moves
 * through the same doors as an approval (FL-503), a refund (FL-506) or an agreed end — once, with their keys.
 */
class Disputes
{
    public function __construct(
        private readonly MilestoneWork $work,
        private readonly MilestoneRelease $release,
        private readonly MilestoneCancellation $cancellation,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** @param  'client'|'freelancer'  $side */
    public function open(Milestone $milestone, Model $opener, string $side, string $reason, string $details): Dispute
    {
        $agreement = $milestone->agreement;
        $this->assertParty($agreement, $opener, $side);

        $dispute = DB::transaction(function () use ($milestone, $agreement, $opener, $side, $reason, $details) {
            /** @var Milestone $m */
            $m = Milestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail()->setRelation('agreement', $agreement);
            if ($agreement->status !== Agreement::ACTIVE || ! in_array($m->status, [Status::Funded, Status::InProgress, Status::Delivered, Status::Revision], true)) {
                throw new FreelanceException('dispute_not_allowed', 409, ['status' => __('freelance.milestone_statuses.'.$m->status->value)], ['status' => $m->status->value]);
            }
            if (Dispute::query()->where('milestone_id', $m->id)->where('status', '!=', Dispute::RESOLVED)->exists()) {
                throw new FreelanceException('dispute_exists', 409);
            }
            $dispute = Dispute::query()->create([
                'project_id' => $m->project_id, 'agreement_id' => $agreement->id, 'milestone_id' => $m->id, 'opened_by' => $side, 'opener_id' => $opener->getKey(),
                'reason' => $reason, 'details' => trim(strip_tags($details)), 'milestone_status' => $m->status->value, 'status' => Dispute::OPEN,
                'respond_by' => now()->addDays($this->days($agreement, 'disputes.response_days', 5)),
            ]);
            // Frozen: a request to end it, or a change to it, that was waiting is over too.
            ChangeRequests::cancelForMilestone($m);
            $this->work->move($m, Status::Disputed, 'milestone.disputed', $side, $opener->getKey(),
                ['cancel_requested_by' => null, 'cancel_requested_at' => null, 'cancel_reason' => null], ['dispute' => $dispute->uuid, 'reason' => $reason]);

            return $dispute;
        });

        $this->tell($dispute, $side === 'client' ? 'freelancer' : 'client', 'dispute.opened');

        return $dispute;
    }

    /** One side shows something: a text, and a file if they have one. While it is open or under appeal. */
    public function addEvidence(Dispute $dispute, Model $author, string $side, string $body, ?UploadedFile $file): Evidence
    {
        $this->assertParty($dispute->agreement, $author, $side);
        if (! in_array($dispute->status, [Dispute::OPEN, Dispute::APPEALED], true)) {
            throw new FreelanceException('dispute_closed_for_evidence', 409);
        }
        $evidence = Evidence::query()->create(['dispute_id' => $dispute->id, 'side' => $side, 'author_id' => $author->getKey(), 'body' => trim(strip_tags($body)), 'created_at' => now()]);
        if ($file !== null) {
            $evidence->addFile($file);
        }

        return $evidence;
    }

    /**
     * The staff's decision. The first one waits for the appeal days; the one after an appeal must come from someone else, is final and
     * runs at once.
     *
     * @param  'refund'|'release'|'split'  $decision
     */
    public function decide(Dispute $dispute, int $adminId, string $decision, ?int $freelancerBp, string $note): Dispute
    {
        if ($decision === 'split' && ($freelancerBp === null || $freelancerBp <= 0 || $freelancerBp >= 10000)) {
            throw new FreelanceException('dispute_split_invalid', 422);
        }
        $final = false;
        $decided = DB::transaction(function () use ($dispute, $adminId, $decision, $freelancerBp, $note, &$final) {
            $d = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($d->status === Dispute::APPEALED) {
                if ((int) $d->decided_by_admin_id === $adminId) {
                    throw new FreelanceException('dispute_same_admin', 422);
                }
                $final = true;
            } elseif ($d->status !== Dispute::OPEN) {
                throw new FreelanceException('dispute_invalid_state', 409, ['status' => __('freelance.dispute_statuses.'.$d->status)], ['status' => $d->status]);
            }
            $appealDays = $this->days($d->agreement, 'disputes.appeal_days', 3);
            $d->fill([
                'decision' => $decision, 'freelancer_bp' => $decision === 'split' ? $freelancerBp : null, 'decision_note' => trim(strip_tags($note)),
                'status' => Dispute::DECIDED, 'decided_at' => now(),
                // A final decision (or a policy with no appeal days) runs now; a first one waits for an appeal.
                'executes_at' => $final || $appealDays === 0 ? now() : now()->addDays($appealDays),
            ] + ($final ? ['final_admin_id' => $adminId] : ['decided_by_admin_id' => $adminId]))->save();
            FreelanceProjectApproval::record((int) $d->project_id, (int) $d->agreement_id, $final ? 'dispute.final_decided' : 'dispute.decided', 'admin', $adminId, null,
                ['dispute' => $d->uuid, 'decision' => $decision] + ($decision === 'split' ? ['freelancer_bp' => $freelancerBp] : []));

            return $d;
        });

        if (! $decided->executes_at->isFuture()) {
            return $this->execute($decided);
        }
        $this->tell($decided, 'client', 'dispute.decided');
        $this->tell($decided, 'freelancer', 'dispute.decided');

        return $decided;
    }

    /** Either side, once, while the first decision waits: another member of staff will decide again. */
    public function appeal(Dispute $dispute, Model $party, string $side, string $reason): Dispute
    {
        $this->assertParty($dispute->agreement, $party, $side);
        $appealed = DB::transaction(function () use ($dispute, $party, $side, $reason) {
            $d = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($d->status !== Dispute::DECIDED || $d->appealed_at !== null || $d->executes_at === null || ! $d->executes_at->isFuture()) {
                throw new FreelanceException('dispute_appeal_closed', 409);
            }
            $d->fill(['status' => Dispute::APPEALED, 'appealed_by' => $side, 'appeal_reason' => trim(strip_tags($reason)), 'appealed_at' => now(), 'executes_at' => null])->save();
            FreelanceProjectApproval::record((int) $d->project_id, (int) $d->agreement_id, 'dispute.appealed', $side, $party->getKey(), null, ['dispute' => $d->uuid, 'reason' => $d->appeal_reason]);

            return $d;
        });
        $this->tell($appealed, $side === 'client' ? 'freelancer' : 'client', 'dispute.appealed');

        return $appealed;
    }

    /** Hourly: the decisions whose appeal days are over, not appealed, run. */
    public function executeDue(): int
    {
        $count = 0;
        Dispute::query()->where('status', Dispute::DECIDED)->whereNotNull('executes_at')->where('executes_at', '<=', now())->get()
            ->each(function (Dispute $d) use (&$count): void {
                try {
                    $this->execute($d);
                    $count++;
                } catch (FreelanceException $e) {
                    logger()->warning('[Freelance] dispute decision not run', ['dispute' => $d->uuid, 'error' => $e->apiErrorCode()]);
                }
            });

        return $count;
    }

    /** Moves the money as decided — once. */
    public function execute(Dispute $dispute): Dispute
    {
        $done = DB::transaction(function () use ($dispute) {
            $d = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($d->status !== Dispute::DECIDED) {
                throw new FreelanceException('dispute_invalid_state', 409, ['status' => __('freelance.dispute_statuses.'.$d->status)], ['status' => $d->status]);
            }
            $m = $d->milestone()->with('agreement.project', 'agreement.client', 'agreement.freelancer')->firstOrFail();
            $adminId = $d->final_admin_id ?? $d->decided_by_admin_id;
            match ($d->decision) {
                'release' => $this->release->approve($m, 'admin', $adminId),
                'refund' => $this->cancellation->refundAll($m, 'admin', $adminId, 'dispute'),
                default => $this->cancellation->settleShare($m, (int) $d->freelancer_bp, 'admin', $adminId, null, false),
            };
            $d->fill(['status' => Dispute::RESOLVED, 'resolved_at' => now()])->save();
            FreelanceProjectApproval::record((int) $d->project_id, (int) $d->agreement_id, 'dispute.resolved', 'system', null, null, ['dispute' => $d->uuid, 'decision' => $d->decision]);

            return $d;
        });
        $done->loadMissing('agreement');
        // A refund doesn't close the project by itself (an approval and a split do).
        $this->release->closeIfSettled($done->agreement);
        $this->tell($done, 'client', 'dispute.resolved');
        $this->tell($done, 'freelancer', 'dispute.resolved');

        return $done;
    }

    /**
     * A dispute of this project, by its uuid — only for its own two sides. (Another freelancer who offered on the project can open the
     * project page; the dispute isn't theirs and looks like it doesn't exist.)
     */
    public function forParty(Project $project, string $uuid, Model $me, string $side): Dispute
    {
        return Dispute::query()->where('project_id', $project->id)->where('uuid', $uuid)
            ->whereHas('agreement', fn ($q) => $q->where($side === 'client' ? 'client_id' : 'freelancer_id', $me->getKey()))
            ->with(['agreement', 'milestone', 'evidence.media'])->firstOrFail();
    }

    /** The current dispute of a part (open, decided or under appeal), else the last one. */
    public static function ofMilestone(Milestone $m): ?Dispute
    {
        return Dispute::query()->where('milestone_id', $m->id)->latest('id')->first();
    }

    /** @return array<string, mixed> `$side` = client, freelancer or admin */
    public function present(Dispute $d, string $side, string $filesPath): array
    {
        $m = $d->milestone;
        $decided = $d->decision !== null;

        return [
            'uuid' => $d->uuid,
            'status' => $d->status,
            'reason' => $d->reason,
            'reason_label' => __('freelance.dispute_reasons.'.$d->reason),
            'details' => $d->details,
            'opened_by' => $d->opened_by,
            'mine' => $side === $d->opened_by,
            'opened_at' => $d->created_at?->toIso8601String(),
            'respond_by' => $d->respond_by?->toIso8601String(),
            'milestone' => $m === null ? null : ['uuid' => $m->uuid, 'title' => $m->title, 'amount_minor' => $m->amount_minor, 'client_fee_minor' => $m->client_fee_minor, 'status' => $m->status->value],
            'currency' => $d->agreement?->currency_code,
            'decision' => ! $decided ? null : [
                'type' => $d->decision,
                'freelancer_bp' => $d->freelancer_bp,
                'note' => $d->decision_note,
                'decided_at' => $d->decided_at?->toIso8601String(),
                'executes_at' => $d->executes_at?->toIso8601String(),
                'final' => $d->final_admin_id !== null,
            ],
            'appeal' => $d->appealed_at === null ? null : ['by' => $d->appealed_by, 'reason' => $d->appeal_reason, 'at' => $d->appealed_at->toIso8601String()],
            'resolved_at' => $d->resolved_at?->toIso8601String(),
            'evidence' => $d->evidence->sortBy('id')->map(fn (Evidence $e) => [
                'id' => $e->id,
                'side' => $e->side,
                'mine' => $side === $e->side,
                'body' => $e->body,
                'file' => ($f = $e->getFirstMedia('files')) === null ? null : ['id' => $f->id, 'name' => $f->file_name, 'mime' => $f->mime_type, 'size' => $f->size, 'path' => "{$filesPath}/{$f->id}"],
                'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'can' => $side === 'admin'
                ? ['decide' => in_array($d->status, [Dispute::OPEN, Dispute::APPEALED], true)]
                : [
                    'evidence' => in_array($d->status, [Dispute::OPEN, Dispute::APPEALED], true),
                    'appeal' => $d->status === Dispute::DECIDED && $d->appealed_at === null && $d->executes_at?->isFuture() === true,
                ],
        ];
    }

    /** One of the dispute's files, for one of its sides or the staff. */
    public function file(Dispute $d, int $mediaId): Media
    {
        $media = Media::query()->whereKey($mediaId)->where('model_type', (new Evidence)->getMorphClass())
            ->whereIn('model_id', $d->evidence()->select('id'))->first();

        return $media ?? abort(404);
    }

    private function days(?Agreement $a, string $key, int $default): int
    {
        $v = ((array) $a?->policy)[$key] ?? config('freelance.policy_defaults.'.$key) ?? $default;

        return max(0, (int) $v);
    }

    private function assertParty(Agreement $a, Model $who, string $side): void
    {
        abort_unless((int) ($side === 'client' ? $a->client_id : $a->freelancer_id) === (int) $who->getKey(), 404);
    }

    /** @param  'client'|'freelancer'  $to */
    private function tell(Dispute $d, string $to, string $event): void
    {
        $d->loadMissing('agreement.client', 'agreement.freelancer', 'project');
        $a = $d->agreement;
        $this->notifier->notify($to === 'client' ? $a->client : $a->freelancer, $event, ['project' => (string) $d->project->title], ['project' => $d->project->uuid, 'dispute' => $d->uuid]);
    }
}
