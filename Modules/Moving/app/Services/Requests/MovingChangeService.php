<?php

namespace Modules\Moving\Services\Requests;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Payment\MovingPricing;
use Modules\Provider\Models\Membership;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\User\Models\User;

/**
 * Changes to an agreed move (B-13): nothing is done or charged before the other side says yes, and the agreed offer
 * is never changed behind anyone's back (REQ-026, REQ-036 → REQ-039). The establishment asks for a price difference
 * (more load, trips, workers) — or the waiting fee, worked out when loading starts (REQ-040); either side asks for a
 * new time (REQ-046). A higher price is paid on the payment screen and held like the rest; a lower one is simply
 * taken less at settlement.
 */
class MovingChangeService
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly MovingPricing $pricing,
        private readonly MovingJournal $journal,
    ) {}

    /** @param array{price_delta_minor: int, workers_count?: int|null, trips_count?: int|null, reason: string} $data */
    public function proposeChange(Membership $actor, MovingRequest $request, array $data): MovingChangeOrder
    {
        $this->assertOwnJob($actor, $request);
        if ($request->driver_membership_id !== $actor->id) {
            $this->access->authorize($actor, 'jobs.change_price', $request->branch);
        }
        if (! $request->status->isActiveJob()) {
            throw new MovingException('cannot_change_now', 409);
        }

        return $this->open($request, 'change', 'provider', $actor->provider_id, [
            'price_delta_minor' => (int) $data['price_delta_minor'],
            'workers_count' => $data['workers_count'] ?? null,
            'trips_count' => $data['trips_count'] ?? null,
            'reason' => $data['reason'],
        ]);
    }

    /** Either side asks for another time while the job hasn't started. */
    public function proposeReschedule(string $by, int $proposerId, MovingRequest $request, CarbonInterface $at, string $reason): MovingChangeOrder
    {
        if (! in_array($request->status, [MovingStatus::Confirmed, MovingStatus::Assigned], true)) {
            throw new MovingException('cannot_change_now', 409);
        }
        if ($at->isPast()) {
            throw new MovingException('time_passed');
        }

        return $this->open($request, 'reschedule', $by, $proposerId, ['new_scheduled_at' => $at, 'reason' => $reason]);
    }

    /**
     * When loading starts: the time past the free minutes after arriving costs the country's hourly fee, up to its
     * cap — asked from the customer like any change, never taken without a yes (REQ-040).
     */
    public function waitingFee(Membership $driver, MovingRequest $request): ?MovingChangeOrder
    {
        $perHour = $request->policy('waiting.fee_per_hour_minor');
        $arrivedAt = $request->events()->where('action', 'arrived')->latest('id')->value('created_at');
        if ($perHour === null || $arrivedAt === null) {
            return null;
        }
        $minutes = (int) floor(Carbon::parse($arrivedAt)->diffInMinutes(now())) - (int) ($request->policy('waiting.grace_minutes') ?? 0);
        if ($minutes <= 0) {
            return null;
        }
        $fee = (int) ceil($minutes / 60 * (int) $perHour);
        if ($request->policy('waiting.cap_minor') !== null) {
            $fee = min($fee, (int) $request->policy('waiting.cap_minor'));
        }
        if ($fee <= 0) {
            return null;
        }

        return $this->open($request, 'waiting', 'provider', $driver->provider_id, ['price_delta_minor' => $fee, 'reason' => "waiting:{$minutes}"]);
    }

    /** The customer's answer to the establishment's change, or the establishment's answer to the customer's new time. */
    public function respond(MovingChangeOrder $change, bool $approve, ?User $user = null, ?Membership $actor = null): MovingChangeOrder
    {
        $request = $change->request;
        if ($change->status !== 'pending') {
            throw new MovingException('change_closed', 409);
        }
        if ($change->proposed_by === 'provider') {
            if ($user === null || $request->user_id !== $user->id) {
                throw new MovingException('change_not_found', 404);
            }
        } else {
            if ($actor === null) {
                throw new MovingException('change_not_found', 404);
            }
            $this->assertOwnJob($actor, $request);
            $this->access->authorize($actor, 'jobs.assign', $request->branch);
        }
        if (! $approve) {
            $change->update(['status' => 'rejected', 'decided_at' => now()]);
            $this->journal->move($request, null, 'change_rejected', $user ? 'user' : 'provider', $user?->id ?? $actor?->provider_id, [], ['change' => $change->uuid]);

            return $change;
        }

        // More to pay: the customer pays the difference on the payment screen first (MovingChangeCheckoutPurpose).
        if ($this->extraTotal($change) > 0) {
            $change->update(['status' => 'awaiting_payment']);

            return $change;
        }

        return DB::transaction(fn () => $this->apply($change));
    }

    /** What the customer pays more for this change (with its tax). */
    public function extraTotal(MovingChangeOrder $change): int
    {
        $request = $change->request;
        if ($change->price_delta_minor <= 0) {
            return 0;
        }
        $after = $this->pricing->forPrice($request, (int) $request->price_minor + $change->price_delta_minor);

        return $after['total_minor'] - (int) $request->total_minor;
    }

    /** Makes the approved change the new agreement, keeping the one before (REQ-036, REQ-038). */
    public function apply(MovingChangeOrder $change): MovingChangeOrder
    {
        $request = MovingRequest::query()->whereKey($change->request_id)->lockForUpdate()->firstOrFail();
        $before = $request->only(['price_minor', 'tax_minor', 'total_minor', 'commission_minor', 'provider_net_minor', 'scheduled_at']);
        $attributes = [];
        if ($change->kind === 'reschedule') {
            $attributes['scheduled_at'] = $change->new_scheduled_at;
        } else {
            $attributes = $this->pricing->forPrice($request, max(0, (int) $request->price_minor + $change->price_delta_minor));
        }
        $change->update(['status' => 'approved', 'decided_at' => now(), 'before' => $before, 'after' => $attributes]);
        $this->journal->move($request, null, 'change_applied', 'system', null, $attributes, ['change' => $change->uuid, 'kind' => $change->kind]);

        return $change;
    }

    /** @param array<string, mixed> $attributes */
    private function open(MovingRequest $request, string $kind, string $by, int $proposerId, array $attributes): MovingChangeOrder
    {
        if (MovingChangeOrder::query()->where('request_id', $request->id)->whereIn('status', ['pending', 'awaiting_payment'])->exists()) {
            throw new MovingException('change_exists', 409);
        }
        $change = MovingChangeOrder::query()->create([
            ...$attributes,
            'request_id' => $request->id,
            'kind' => $kind,
            'proposed_by' => $by,
            'proposer_id' => $proposerId,
            'status' => 'pending',
        ]);
        $this->journal->move($request, null, 'change_requested', $by === 'user' ? 'user' : 'provider', $proposerId, [], ['change' => $change->uuid, 'kind' => $kind]);

        return $change;
    }

    private function assertOwnJob(Membership $actor, MovingRequest $request): void
    {
        if ($request->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }
    }
}
