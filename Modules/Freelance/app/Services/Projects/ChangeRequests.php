<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceChangeRequest as Change;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Changing an agreement after it started (FL-404 / FL-405): one side asks, the other answers within `changes.response_days` (the policy
 * frozen in the agreement, default 3). Nothing changes before the answer, and no money moves at all:
 *
 *  - `extend_days`: more days for the agreement or one part (no effect on the review days of a delivery).
 *  - `scope`: the text of what the work covers, includes and doesn't.
 *  - `milestone_price`: a new price for a part nobody funded yet (up or down); its fee, commission and net are worked out again from the
 *    frozen policy, and the agreement's price follows.
 *  - `add_milestone`: more work becomes a new part, not funded — the client funds it like any other. A part already funded or started
 *    never changes its price: work beyond it is a new part.
 *
 * One request waits at a time on a part (or on the agreement itself), not on a part in dispute or with a request to end it, and at most
 * five changes are accepted per agreement. Every step is in `freelance_project_approvals` (FL-406).
 */
class ChangeRequests
{
    public const MAX_ACCEPTED = 5;

    public function __construct(private readonly Milestones $milestones, private readonly FreelanceNotifier $notifier) {}

    /**
     * @param  'client'|'freelancer'  $side
     * @param  array<string, mixed>  $data
     */
    public function request(Agreement $agreement, Model $actor, string $side, string $type, array $data): Change
    {
        $this->assertParty($agreement, $actor, $side);
        $change = DB::transaction(function () use ($agreement, $actor, $side, $type, $data) {
            $a = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->with('project')->firstOrFail();
            if ($a->status !== Agreement::ACTIVE) {
                throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
            }
            if (Change::query()->where('agreement_id', $a->id)->where('status', 'accepted')->count() >= self::MAX_ACCEPTED) {
                throw new FreelanceException('changes_limit', 422, ['max' => self::MAX_ACCEPTED], ['max' => self::MAX_ACCEPTED]);
            }
            $m = isset($data['milestone']) ? Milestone::query()->where('agreement_id', $a->id)->where('uuid', $data['milestone'])->firstOrFail() : null;
            if ($m !== null) {
                $this->assertChangeablePart($m);
            }
            $waiting = Change::query()->where('agreement_id', $a->id)->where('status', Change::PENDING)
                ->where(fn ($q) => $m === null ? $q->whereNull('milestone_id') : $q->where('milestone_id', $m->id))->get()->first(fn (Change $c) => $c->open());
            if ($waiting !== null) {
                throw new FreelanceException('change_pending', 409);
            }

            $payload = $this->payload($a, $m, $type, $data);

            $change = Change::query()->create([
                'agreement_id' => $a->id, 'project_id' => $a->project_id, 'milestone_id' => $m?->id, 'type' => $type, 'requested_by' => $side,
                'requester_id' => $actor->getKey(), 'payload' => $payload, 'reason' => isset($data['reason']) ? (trim(strip_tags((string) $data['reason'])) ?: null) : null,
                'status' => Change::PENDING, 'respond_by' => now()->addDays($this->days($a)),
            ]);
            $this->approval($a, 'change.requested', $side, $actor->getKey(), ['change' => $change->uuid, 'type' => $type] + ($m === null ? [] : ['milestone' => $m->uuid]));

            return $change;
        });
        $this->tell($change, $side === 'client' ? 'freelancer' : 'client', 'change.requested');

        return $change;
    }

    /** The other side agrees: the change is made now, checked again on the locked rows. */
    public function accept(Change $change, Model $actor, string $side): Change
    {
        $stale = null;
        $done = DB::transaction(function () use ($change, $actor, $side, &$stale) {
            $c = $this->answerable($change, $actor, $side);
            $a = Agreement::query()->whereKey($c->agreement_id)->lockForUpdate()->with('project')->firstOrFail();
            if ($a->status !== Agreement::ACTIVE) {
                throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
            }
            $m = $c->milestone_id === null ? null : Milestone::query()->whereKey($c->milestone_id)->lockForUpdate()->firstOrFail();
            // The part moved on since it was asked (funded, settled, in dispute…): the request can't be made any more, so it ends
            // here instead of holding the part's place until it runs out — and the one who asked is told.
            try {
                if ($m !== null) {
                    $this->assertChangeablePart($m);
                    if ($c->type === 'milestone_price' && $m->status !== Status::Unfunded) {
                        throw new FreelanceException('change_part_funded', 409);
                    }
                }
            } catch (FreelanceException $e) {
                $c->fill(['status' => 'cancelled', 'answered_at' => now()])->save();
                $this->approval($a, 'change.cancelled', 'system', null, ['change' => $c->uuid, 'type' => $c->type]);
                $stale = $e;

                return $c;
            }
            $this->apply($a, $m, $c);
            $c->fill(['status' => 'accepted', 'answered_at' => now()])->save();
            $a->forceFill(['version' => $a->version + 1])->save();
            $this->approval($a, 'change.accepted', $side, $actor->getKey(), ['change' => $c->uuid, 'type' => $c->type]);

            return $c;
        });
        if ($stale !== null) {
            $this->tell($done, $done->requested_by, 'change.cancelled');
            throw $stale;
        }
        $this->tell($done, $done->requested_by, 'change.accepted');

        return $done;
    }

    public function reject(Change $change, Model $actor, string $side): Change
    {
        $done = DB::transaction(function () use ($change, $actor, $side) {
            $c = $this->answerable($change, $actor, $side);
            $c->fill(['status' => 'rejected', 'answered_at' => now()])->save();
            $this->approval($c->agreement, 'change.rejected', $side, $actor->getKey(), ['change' => $c->uuid, 'type' => $c->type]);

            return $c;
        });
        $this->tell($done, $done->requested_by, 'change.rejected');

        return $done;
    }

    /** The one who asked takes it back while it waits. */
    public function withdraw(Change $change, Model $actor, string $side): Change
    {
        return DB::transaction(function () use ($change, $actor, $side) {
            $c = Change::query()->whereKey($change->id)->lockForUpdate()->with('agreement')->firstOrFail();
            $this->assertParty($c->agreement, $actor, $side);
            if ($c->requested_by !== $side || ! $c->open()) {
                throw new FreelanceException('change_not_open', 409);
            }
            $c->fill(['status' => 'withdrawn', 'answered_at' => now()])->save();
            $this->approval($c->agreement, 'change.withdrawn', $side, $actor->getKey(), ['change' => $c->uuid, 'type' => $c->type]);

            return $c;
        });
    }

    /** A part going into dispute takes its waiting requests with it. */
    public static function cancelForMilestone(Milestone $m): void
    {
        Change::query()->where('milestone_id', $m->id)->where('status', Change::PENDING)->update(['status' => 'cancelled', 'answered_at' => now()]);
    }

    /** @return list<array<string, mixed>> the agreement's requests, newest first, as this side sees them */
    public function list(Agreement $a, string $side): array
    {
        return Change::query()->where('agreement_id', $a->id)->with('milestone')->latest('id')->limit(30)->get()->map(fn (Change $c) => [
            'uuid' => $c->uuid,
            'type' => $c->type,
            'status' => $c->shownStatus(),
            'requested_by' => $c->requested_by,
            'mine' => $c->requested_by === $side,
            'milestone' => $c->milestone === null ? null : ['uuid' => $c->milestone->uuid, 'title' => $c->milestone->title],
            'payload' => $c->payload,
            'reason' => $c->reason,
            'respond_by' => $c->respond_by?->toIso8601String(),
            'at' => $c->created_at?->toIso8601String(),
            'can' => [
                'accept' => $c->open() && $c->requested_by !== $side,
                'reject' => $c->open() && $c->requested_by !== $side,
                'withdraw' => $c->open() && $c->requested_by === $side,
            ],
        ])->values()->all();
    }

    public function find(Agreement $a, string $uuid): Change
    {
        return Change::query()->where('agreement_id', $a->id)->where('uuid', $uuid)->firstOrFail();
    }

    // ------------------------------------------------------------------ inside

    /**
     * What the request would change, checked now (and again when it is accepted).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(Agreement $a, ?Milestone $m, string $type, array $data): array
    {
        $policy = (array) $a->policy;

        return match ($type) {
            'extend_days' => ['days' => max(1, (int) ($data['days'] ?? 0)), 'from' => $m === null ? $a->duration_days : $m->duration_days],
            'scope' => $this->scopePayload($a, $data),
            'milestone_price' => $this->pricePayload($a, $m, (int) ($data['amount_minor'] ?? 0)),
            'add_milestone' => (function () use ($a, $data, $policy) {
                $count = $this->milestones->of($a)->count();
                $max = max(1, (int) ($policy['milestones.max_count'] ?? 5));
                if ($count >= $max) {
                    throw new FreelanceException('milestones_too_many', 422, ['max' => $max], ['max' => $max]);
                }
                $amount = (int) ($data['amount_minor'] ?? 0);
                $this->assertMinimum($a, $amount);

                return ['title' => mb_substr(trim(strip_tags((string) ($data['title'] ?? ''))), 0, 120), 'amount_minor' => $amount, 'duration_days' => isset($data['duration_days']) ? (int) $data['duration_days'] : null];
            })(),
            default => throw new FreelanceException('change_invalid', 422),
        };
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function scopePayload(Agreement $a, array $data): array
    {
        $out = [];
        foreach (['scope', 'includes', 'excludes'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = ['from' => $a->{$key}, 'to' => ($t = trim(strip_tags((string) $data[$key]))) === '' ? null : $t];
            }
        }
        if ($out === [] || (isset($out['scope']) && $out['scope']['to'] === null)) {
            throw new FreelanceException('change_invalid', 422);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function pricePayload(Agreement $a, ?Milestone $m, int $amount): array
    {
        if ($m === null) {
            throw new FreelanceException('change_invalid', 422);
        }
        // A funded or started part keeps its price: more work there is a new part.
        if ($m->status !== Status::Unfunded) {
            throw new FreelanceException('change_part_funded', 409);
        }
        if ($amount === $m->amount_minor || $amount <= 0) {
            throw new FreelanceException('change_invalid', 422);
        }
        if ($this->milestones->of($a)->count() > 1) {
            $this->assertMinimum($a, $amount);
        }

        return ['from' => $m->amount_minor, 'to' => $amount];
    }

    private function apply(Agreement $a, ?Milestone $m, Change $c): void
    {
        $p = (array) $c->payload;
        match ($c->type) {
            'extend_days' => $m === null
                ? $a->forceFill(['duration_days' => (int) $a->duration_days + (int) $p['days']])->save()
                : $m->forceFill(['duration_days' => (int) $m->duration_days + (int) $p['days']])->save(),
            'scope' => $a->forceFill(collect($p)->map(fn ($v) => $v['to'])->all())->save(),
            'milestone_price' => $this->reprice($a, $m, (int) $p['to']),
            'add_milestone' => $this->addPart($a, $p),
            default => null,
        };
        if (in_array($c->type, ['milestone_price', 'add_milestone'], true)) {
            $totals = $this->milestones->totals($a);
            $a->forceFill(['price_minor' => $totals['price_minor'], 'money' => $totals + ['quoted_total_minor' => ((array) $a->money)['quoted_total_minor'] ?? null]])->save();
        }
    }

    private function reprice(Agreement $a, Milestone $m, int $amount): void
    {
        if ($m->status !== Status::Unfunded) {
            throw new FreelanceException('change_part_funded', 409);
        }
        $b = $this->milestones->numbers($a, $amount);
        $m->forceFill(['amount_minor' => $b['amount_minor'], 'client_fee_minor' => $b['client_fee_minor'], 'commission_minor' => $b['commission_minor'], 'net_minor' => $b['freelancer_net_minor']])->save();
    }

    /** @param  array<string, mixed>  $p */
    private function addPart(Agreement $a, array $p): void
    {
        $b = $this->milestones->numbers($a, (int) $p['amount_minor']);
        Milestone::query()->create([
            'agreement_id' => $a->id, 'project_id' => $a->project_id, 'position' => (int) Milestone::query()->where('agreement_id', $a->id)->max('position') + 1,
            'title' => $p['title'] ?: __('freelance.milestone_extra'), 'amount_minor' => $b['amount_minor'], 'duration_days' => $p['duration_days'],
            'client_fee_minor' => $b['client_fee_minor'], 'commission_minor' => $b['commission_minor'], 'net_minor' => $b['freelancer_net_minor'],
            'status' => Status::Unfunded,
        ]);
    }

    private function assertMinimum(Agreement $a, int $amount): void
    {
        $min = ((array) $a->policy)['milestones.min_amount_minor'] ?? null;
        if ($amount <= 0 || ($min !== null && $amount < (int) $min)) {
            throw new FreelanceException('milestone_too_small', 422, ['min' => number_format(((int) $min) / 100, 2).' '.$a->currency_code], ['min_minor' => (int) $min]);
        }
    }

    /** Not on a part that is settled, in dispute, or with a request to end it waiting. */
    private function assertChangeablePart(Milestone $m): void
    {
        if ($m->status->settled() || $m->status === Status::Disputed || ($m->cancel_requested_by !== null && app(MilestoneCancellation::class)->open($m))) {
            throw new FreelanceException('change_not_allowed', 409, ['status' => __('freelance.milestone_statuses.'.$m->status->value)], ['status' => $m->status->value]);
        }
    }

    private function answerable(Change $change, Model $actor, string $side): Change
    {
        $c = Change::query()->whereKey($change->id)->lockForUpdate()->with('agreement')->firstOrFail();
        $this->assertParty($c->agreement, $actor, $side);
        if (! $c->open() || $c->requested_by === $side) {
            throw new FreelanceException('change_not_open', 409);
        }

        return $c;
    }

    private function days(Agreement $a): int
    {
        return max(1, (int) (((array) $a->policy)['changes.response_days'] ?? config('freelance.policy_defaults.changes.response_days') ?? 3));
    }

    private function assertParty(Agreement $a, Model $who, string $side): void
    {
        abort_unless((int) ($side === 'client' ? $a->client_id : $a->freelancer_id) === (int) $who->getKey(), 404);
    }

    /** @param  array<string, mixed>  $payload */
    private function approval(Agreement $a, string $action, string $actorType, mixed $actorId, array $payload): void
    {
        FreelanceProjectApproval::forAgreement($a, $action, $actorType, $actorId, $payload);
    }

    /** @param  'client'|'freelancer'  $to */
    private function tell(Change $c, string $to, string $event): void
    {
        $c->loadMissing('agreement.client', 'agreement.freelancer', 'agreement.project');
        $a = $c->agreement;
        $this->notifier->notify($to === 'client' ? $a->client : $a->freelancer, $event, ['project' => (string) $a->project->title], ['project' => $a->project->uuid, 'change' => $c->uuid]);
    }
}
