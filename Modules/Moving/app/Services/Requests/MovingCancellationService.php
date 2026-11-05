<?php

namespace Modules\Moving\Services\Requests;

use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Payment\MovingMoney;
use Modules\Provider\Models\Membership;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\User\Models\User;

/**
 * Cancelling after an offer is accepted (B-12, owner's call §15 joined with REQ-041 → REQ-043). Every cancellation
 * names a reason. A reason that blames the other side or is force majeure opens an exception that a person decides —
 * no fee meanwhile. Otherwise the customer pays the fee for how far the job went (announced before paying, REQ-043),
 * which goes to the establishment, and gets the rest back; once the truck is loaded there is no cancelling, only an
 * exception. The establishment may cancel before its driver sets out: the customer gets everything back.
 */
class MovingCancellationService
{
    public function __construct(
        private readonly MovingMoney $money,
        private readonly MovingJournal $journal,
        private readonly EstablishmentAccess $access,
    ) {}

    /** The fee the customer would pay now, in minor units (0 when the country set none). */
    public function customerFee(MovingRequest $request): int
    {
        $key = match ($request->status) {
            MovingStatus::Confirmed, MovingStatus::Assigned => $this->isNear($request) ? 'cancel.customer.near_bp' : 'cancel.customer.early_bp',
            MovingStatus::DriverOnWay => 'cancel.customer.on_way_bp',
            MovingStatus::Arrived => 'cancel.customer.arrived_bp',
            default => null,
        };

        return $key === null ? 0 : intdiv((int) $request->price_minor * (int) ($request->policy($key) ?? 0), 10000);
    }

    public function byCustomer(User $user, MovingRequest $request, string $reason, ?string $details): MovingRequest|MovingCase
    {
        if ($request->user_id !== $user->id) {
            throw new MovingException('request_not_found', 404);
        }
        $this->assertReason('user', $reason);
        if (in_array($reason, (array) config('moving.exception_reasons', []), true)) {
            return $this->openException($request, 'user', $user->id, $reason, $details);
        }
        if (! in_array($request->status, [MovingStatus::Confirmed, MovingStatus::Assigned, MovingStatus::DriverOnWay, MovingStatus::Arrived], true)) {
            throw new MovingException('cannot_cancel_now', 409);
        }

        return DB::transaction(function () use ($user, $request, $reason, $details): MovingRequest {
            $request = MovingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $fee = $this->customerFee($request);
            $this->money->take($request, $fee, 'cancel');
            $this->money->payEstablishment($request, $fee, 'cancel', 'moving_cancellation_fee');

            return $this->journal->move($request, MovingStatus::Cancelled, 'cancelled', 'user', $user->id, [
                'cancelled_by' => 'user',
                'cancel_reason' => $reason,
                'payment_status' => 'refunded',
                'refunded_minor' => (int) $request->total_minor - $fee,
            ], ['fee_minor' => $fee, 'details' => $details]);
        });
    }

    public function byEstablishment(Membership $actor, MovingRequest $request, string $reason, ?string $details): MovingRequest|MovingCase
    {
        if ($request->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }
        $this->access->authorize($actor, 'jobs.cancel', $request->branch);
        $this->assertReason('provider', $reason);
        if (in_array($reason, (array) config('moving.exception_reasons', []), true)) {
            return $this->openException($request, 'provider', $actor->provider_id, $reason, $details);
        }
        if (! in_array($request->status, [MovingStatus::Confirmed, MovingStatus::Assigned], true)) {
            throw new MovingException('cannot_cancel_now', 409);
        }

        return DB::transaction(function () use ($actor, $request, $reason, $details): MovingRequest {
            $request = MovingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $refunded = $this->money->refundAll($request, 'provider_cancel');
            $request->acceptedOffer?->update(['status' => OfferStatus::Withdrawn]);

            return $this->journal->move($request, MovingStatus::Cancelled, 'cancelled', 'provider', $actor->provider_id, [
                'cancelled_by' => 'provider',
                'cancel_reason' => $reason,
                'payment_status' => 'refunded',
                'refunded_minor' => $refunded,
            ], ['details' => $details]);
        });
    }

    /**
     * A person's decision on an exception: `refund_all` gives the customer everything back and ends the job;
     * `fee` lets the customer pay the stage fee; `continue` leaves the job as it is.
     */
    public function decideException(MovingCase $case, string $outcome, ?string $note, int $adminId): MovingCase
    {
        if ($case->kind !== MovingCase::EXCEPTION || ! $case->isOpen()) {
            throw new MovingException('case_closed', 409);
        }

        return DB::transaction(function () use ($case, $outcome, $note, $adminId): MovingCase {
            $request = MovingRequest::query()->whereKey($case->request_id)->lockForUpdate()->firstOrFail();
            $fee = $outcome === 'fee' ? $this->customerFee($request) : 0;
            if ($outcome !== 'continue') {
                $this->money->take($request, $fee, 'exception');
                $this->money->payEstablishment($request, $fee, 'exception', 'moving_cancellation_fee');
                $this->journal->move($request, MovingStatus::Cancelled, 'cancelled', 'admin', $adminId, [
                    'cancelled_by' => $case->opened_by,
                    'cancel_reason' => $case->reason_code,
                    'payment_status' => 'refunded',
                    'refunded_minor' => (int) $request->total_minor - $fee,
                ], ['case' => $case->uuid, 'fee_minor' => $fee]);
            }
            $case->update([
                'status' => 'decided', 'decision' => $outcome === 'continue' ? 'rejected' : 'accepted',
                'award_minor' => $outcome === 'continue' ? null : (int) $request->total_minor - $fee,
                'decision_note' => $note, 'decided_by_admin_id' => $adminId, 'decided_at' => now(),
            ]);

            return $case;
        });
    }

    private function openException(MovingRequest $request, string $by, int $openerId, string $reason, ?string $details): MovingCase
    {
        if (! $request->status->isActiveJob()) {
            throw new MovingException('cannot_cancel_now', 409);
        }
        if ($request->cases()->where('kind', MovingCase::EXCEPTION)->where('status', 'open')->exists()) {
            throw new MovingException('exception_exists', 409);
        }
        $case = $request->cases()->create([
            'kind' => MovingCase::EXCEPTION, 'opened_by' => $by, 'opener_id' => $openerId,
            'reason_code' => $reason, 'details' => $details, 'status' => 'open',
        ]);
        $this->journal->move($request, null, 'exception_requested', $by === 'user' ? 'user' : 'provider', $openerId, [], ['case' => $case->uuid, 'reason' => $reason]);

        return $case;
    }

    private function isNear(MovingRequest $request): bool
    {
        $hours = $request->policy('cancel.near_hours');

        return $hours !== null && $request->scheduled_at !== null && $request->scheduled_at->copy()->subHours((int) $hours)->isPast();
    }

    private function assertReason(string $side, string $reason): void
    {
        if (! in_array($reason, (array) config("moving.cancel_reasons.{$side}", []), true)) {
            throw new MovingException('bad_reason');
        }
    }
}
