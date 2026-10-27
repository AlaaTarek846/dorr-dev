<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * The work on the parts of an active agreement (FL-403). One door for every move (`move`): the row is locked, the move is checked
 * against the status, `version` goes up and an event is written (`freelance_milestone_events`, never deleted) — a move that isn't
 * allowed answers `freelance_milestone_invalid_state` (409). The parts go in order: one starts only when every one before it is
 * approved or cancelled. The freelancer starts and delivers; the client approves, asks for changes or cancels a part not yet funded.
 * Funding (FL-501), paying out (FL-503) and disputes (FL-704) call `move` through their own services.
 */
class MilestoneWork
{
    /** FL-406: the moves of a part that go in the project's record of approvals. */
    public const APPROVALS = ['milestone.funded', 'milestone.delivered', 'milestone.revision', 'milestone.approved', 'milestone.auto_approved', 'milestone.cancelled', 'milestone.disputed'];

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /** A part of this project's active agreement, by its uuid, else not found. */
    public function find(Project $project, string $uuid): Milestone
    {
        return Milestone::query()->where('project_id', $project->id)->where('uuid', $uuid)
            ->whereHas('agreement', fn ($q) => $q->where('status', Agreement::ACTIVE))
            ->with(['agreement.client', 'agreement.freelancer', 'agreement.project'])->firstOrFail();
    }

    /** Funded (FL-501 calls this once the money is held). */
    public function fund(Milestone $m, string $actorType, ?int $actorId): Milestone
    {
        $this->assertInTurn($m);

        return $this->move($m, Status::Funded, 'milestone.funded', $actorType, $actorId, ['funded_at' => now()]);
    }

    public function start(Milestone $m, Freelancer $freelancer): Milestone
    {
        $this->assertFreelancer($m, $freelancer);
        $this->assertInTurn($m);

        return $this->move($m, Status::InProgress, 'milestone.started', 'freelancer', $freelancer->id, ['started_at' => now()]);
    }

    /**
     * FL-601: the delivery is a new numbered version (its note and files), made in the same transaction as the move.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     */
    public function deliver(Milestone $m, Freelancer $freelancer, string $note, array $files = []): Milestone
    {
        $this->assertFreelancer($m, $freelancer);
        $moved = DB::transaction(function () use ($m, $freelancer, $note, $files): Milestone {
            $delivery = app(MilestoneDeliveries::class)->record($m, $note, $files);

            return $this->move($m, Status::Delivered, 'milestone.delivered', 'freelancer', $freelancer->id, ['delivered_at' => now(), 'delivery_note' => $delivery->note, 'review_reminded_at' => null,
                // FL-506: a request to end the part, still waiting, is over once it is delivered.
                'cancel_requested_by' => null, 'cancel_requested_at' => null, 'cancel_reason' => null], ['delivery' => $delivery->number, 'files' => count($files)]);
        });
        $this->notifier->notify($m->agreement->client, 'milestone.delivered', ['project' => (string) $m->agreement->project->title, 'name' => (string) $freelancer->name], ['project' => $m->agreement->project->uuid, 'milestone' => $m->uuid]);

        return $moved;
    }

    public function approve(Milestone $m, User $client): Milestone
    {
        $this->assertClient($m, $client);

        // FL-503: approving pays the part out, in the same transaction.
        return app(MilestoneRelease::class)->approve($m, 'client', $client->id);
    }

    public function requestRevision(Milestone $m, User $client, string $reason): Milestone
    {
        $this->assertClient($m, $client);
        // FL-407: only so many rounds are free; after them the client approves or goes to the dispute (FL-704).
        $free = $this->freeRevisions($m);
        if ($m->status === Status::Delivered && $m->revisions_used >= $free) {
            throw new FreelanceException('revisions_exhausted', 409, ['max' => $free], ['max' => $free, 'used' => $m->revisions_used]);
        }
        $reason = trim(strip_tags($reason));
        $moved = DB::transaction(function () use ($m, $client, $reason): Milestone {
            $moved = $this->move($m, Status::Revision, 'milestone.revision', 'client', $client->id, ['revision_reason' => $reason, 'revisions_used' => $m->revisions_used + 1], ['reason' => $reason]);
            // FL-603: the changes are written on the version they are about, and the checklist starts again for the next one.
            app(MilestoneDeliveries::class)->markRevision($moved, $reason);
            app(MilestoneDeliveries::class)->clearTicks($moved);

            return $moved;
        });
        $this->notifier->notify($m->agreement->freelancer, 'milestone.revision', ['project' => (string) $m->agreement->project->title], ['project' => $m->agreement->project->uuid, 'milestone' => $m->uuid]);
        // That was the last free round: the freelancer knows no more are coming for free.
        if ($moved->revisions_used === $free) {
            $this->notifier->notify($m->agreement->freelancer, 'milestone.revisions_exhausted', ['project' => (string) $m->agreement->project->title], ['project' => $m->agreement->project->uuid, 'milestone' => $m->uuid]);
        }

        return $moved;
    }

    /** Only a part nobody paid for yet; after funding, ending a part goes through a refund (FL-506) or a dispute. */
    public function cancel(Milestone $m, User $client): Milestone
    {
        $this->assertClient($m, $client);
        if ($m->status !== Status::Unfunded) {
            throw new FreelanceException('milestone_invalid_state', 409, ['status' => __('freelance.milestone_statuses.'.$m->status->value)], ['status' => $m->status->value]);
        }

        $moved = $this->move($m, Status::Cancelled, 'milestone.cancelled', 'client', $client->id);
        // FL-408: the last open part gone — the project may be done.
        app(MilestoneRelease::class)->closeIfSettled($m->agreement);

        return $moved;
    }

    /** FL-407: the free rounds of changes, from the policy frozen in the agreement. */
    public function freeRevisions(Milestone $m): int
    {
        return max(0, (int) (((array) $m->agreement->policy)['review.free_revisions'] ?? 0));
    }

    /** Every part approved or cancelled (FL-408 closes the project then). */
    public function allSettled(Agreement $a): bool
    {
        return ! Milestone::query()->where('agreement_id', $a->id)->whereNotIn('status', [Status::Approved->value, Status::Cancelled->value])->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    public function move(Milestone $m, Status $to, string $event, string $actorType, ?int $actorId, array $attributes = [], array $payload = []): Milestone
    {
        return DB::transaction(function () use ($m, $to, $event, $actorType, $actorId, $attributes, $payload) {
            /** @var Milestone $locked */
            $locked = Milestone::query()->whereKey($m->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            if (! $from->canMoveTo($to)) {
                throw new FreelanceException('milestone_invalid_state', 409, ['status' => __('freelance.milestone_statuses.'.$from->value)], ['status' => $from->value]);
            }
            $locked->fill($attributes);
            $locked->status = $to;
            $locked->version = $locked->version + 1;
            $locked->save();
            DB::table('freelance_milestone_events')->insert([
                'milestone_id' => $locked->id, 'type' => $event, 'from_status' => $from->value, 'to_status' => $to->value,
                'actor_type' => $actorType, 'actor_id' => $actorId, 'payload' => $payload === [] ? null : json_encode($payload), 'created_at' => now(),
            ]);
            if (in_array($event, self::APPROVALS, true)) {
                FreelanceProjectApproval::record((int) $locked->project_id, (int) $locked->agreement_id, $event, $actorType, $actorId, (int) $locked->version,
                    ['milestone' => $locked->uuid, 'title' => $locked->title] + $payload);
            }

            return $locked->setRelations($m->getRelations());
        });
    }

    /** @return list<array{type: string, to: ?string, at: ?string}> */
    public function history(Milestone $m): array
    {
        return DB::table('freelance_milestone_events')->where('milestone_id', $m->id)->orderBy('id')->get()
            ->map(fn ($e) => ['type' => $e->type, 'to' => $e->to_status, 'at' => $e->created_at ? \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String() : null])->all();
    }

    /** @return array<string, bool> what this side may do with the part now */
    public function can(Milestone $m, string $side): array
    {
        $inTurn = $this->inTurn($m);
        $cancel = app(MilestoneCancellation::class);
        $asked = $cancel->open($m) ? $m->cancel_requested_by : null;
        $started = in_array($m->status, [Status::InProgress, Status::Revision], true);
        // FL-704: a dispute is possible on a part with money held, once at a time.
        $disputable = in_array($m->status, [Status::Funded, Status::InProgress, Status::Delivered, Status::Revision], true);

        return $side === 'client'
            ? ['fund' => $m->status === Status::Unfunded && $inTurn, 'approve' => $m->status === Status::Delivered, 'revision' => $m->status === Status::Delivered && $m->revisions_used < $this->freeRevisions($m),
                // FL-604: the list of what to check is written before the work starts, ticked while reviewing.
                'checklist_edit' => in_array($m->status, [Status::Unfunded, Status::Funded], true), 'checklist_tick' => $m->status === Status::Delivered,
                'cancel' => ($m->status === Status::Unfunded || $m->status === Status::Funded || $started) && $asked === null,
                'cancel_accept' => $started && $asked === 'freelancer', 'cancel_reject' => $started && $asked === 'freelancer',
                'dispute' => $disputable]
            : ['start' => $m->status === Status::Funded && $inTurn, 'deliver' => $started,
                'withdraw' => ($m->status === Status::Funded || $started) && $asked === null,
                'cancel_accept' => $started && $asked === 'client', 'cancel_reject' => $started && $asked === 'client',
                'dispute' => $disputable];
    }

    private function inTurn(Milestone $m): bool
    {
        return ! Milestone::query()->where('agreement_id', $m->agreement_id)->where('position', '<', $m->position)
            ->whereNotIn('status', [Status::Approved->value, Status::Cancelled->value])->exists();
    }

    private function assertInTurn(Milestone $m): void
    {
        if (! $this->inTurn($m)) {
            throw new FreelanceException('milestone_out_of_turn', 409);
        }
    }

    private function assertFreelancer(Milestone $m, Freelancer $f): void
    {
        abort_unless((int) $m->agreement->freelancer_id === (int) $f->id, 404);
    }

    private function assertClient(Milestone $m, User $c): void
    {
        abort_unless((int) $m->agreement->client_id === (int) $c->id, 404);
    }
}
