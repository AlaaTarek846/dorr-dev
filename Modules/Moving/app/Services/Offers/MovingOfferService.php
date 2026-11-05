<?php

namespace Modules\Moving\Services\Offers;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Requests\MovingJournal;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Establishments\WorkGate;

/**
 * An establishment's offers (B-04, REQ-021): only on a request that reached one of its branches, only with an
 * approved vehicle whose photos and papers are valid (§6), one offer per establishment per request (§5), and an
 * offer above the sender's limit waits for someone allowed to approve it (§17). A vehicle already booked near that
 * time is refused (REQ-027).
 */
class MovingOfferService
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly WorkGate $gate,
        private readonly MovingJournal $journal,
    ) {}

    /**
     * @param  array{vehicle_uuid: string, price_minor: int, workers_count?: int, trips_count?: int, includes?: array<string, bool>, technician_note?: string|null, arrival_at: string, terms?: string|null, after_service?: string|null, valid_until: string}  $data
     */
    public function send(Membership $actor, MovingRequest $request, array $data): MovingOffer
    {
        $branch = $this->reachedBranch($actor, $request);
        $this->assertOpen($request);
        $vehicle = Vehicle::query()->where('uuid', $data['vehicle_uuid'])->where('establishment_id', $actor->establishment_id)->first()
            ?? throw new MovingException('vehicle_not_found', 404);
        if ($problem = $this->gate->offerProblem($actor, $vehicle, $request->service_category_id)) {
            throw new MovingException('cannot_offer', 403, ['reason' => __('provider.errors.'.$problem)], ['reason' => $problem]);
        }

        return DB::transaction(function () use ($actor, $request, $data, $branch, $vehicle): MovingOffer {
            MovingRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            $exists = MovingOffer::query()->where('request_id', $request->id)->where('establishment_id', $actor->establishment_id)
                ->whereIn('status', [OfferStatus::Sent->value, OfferStatus::AwaitingApproval->value])->exists();
            if ($exists) {
                throw new MovingException('offer_exists', 409);
            }
            $arrival = Carbon::parse($data['arrival_at']);
            $this->assertVehicleFree($request, $vehicle, $arrival);

            $offer = MovingOffer::query()->create([
                'request_id' => $request->id,
                'establishment_id' => $actor->establishment_id,
                'branch_id' => $branch->id,
                'vehicle_id' => $vehicle->id,
                'created_by_membership_id' => $actor->id,
                'price_minor' => $data['price_minor'],
                'currency_code' => $request->currency_code,
                'workers_count' => $data['workers_count'] ?? (int) $request->workers_count,
                'trips_count' => $data['trips_count'] ?? 1,
                'includes' => $data['includes'] ?? null,
                'technician_note' => $data['technician_note'] ?? null,
                'arrival_at' => $arrival,
                'terms' => $data['terms'] ?? null,
                'after_service' => $data['after_service'] ?? null,
                'valid_until' => $this->validUntil($request, $data['valid_until']),
                'status' => $this->needsApproval($actor, (int) $data['price_minor']) ? OfferStatus::AwaitingApproval : OfferStatus::Sent,
            ]);
            if ($offer->status === OfferStatus::Sent) {
                $this->journal->move($request, null, 'offer_received', 'provider', $actor->provider_id, [], ['offer' => $offer->uuid]);
            }

            return $offer;
        });
    }

    /** Changed or taken back while the customer hasn't chosen (offers.manage). */
    public function update(Membership $actor, MovingOffer $offer, array $data): MovingOffer
    {
        $this->assertManageable($actor, $offer);
        $request = $offer->request;
        $this->assertOpen($request);
        if (isset($data['vehicle_uuid'])) {
            $vehicle = Vehicle::query()->where('uuid', $data['vehicle_uuid'])->where('establishment_id', $actor->establishment_id)->first()
                ?? throw new MovingException('vehicle_not_found', 404);
            if ($problem = $this->gate->offerProblem($actor, $vehicle, $request->service_category_id)) {
                throw new MovingException('cannot_offer', 403, ['reason' => __('provider.errors.'.$problem)], ['reason' => $problem]);
            }
            $offer->vehicle_id = $vehicle->id;
        }
        $offer->fill(array_intersect_key($data, array_flip(['price_minor', 'workers_count', 'trips_count', 'includes', 'technician_note', 'terms', 'after_service'])));
        if (isset($data['arrival_at'])) {
            $offer->arrival_at = Carbon::parse($data['arrival_at']);
        }
        if (isset($data['valid_until'])) {
            $offer->valid_until = $this->validUntil($request, $data['valid_until']);
        }
        $this->assertVehicleFree($request, $offer->vehicle, $offer->arrival_at, $offer->id);
        if ($offer->isDirty('price_minor')) {
            $offer->status = $this->needsApproval($actor, (int) $offer->price_minor) ? OfferStatus::AwaitingApproval : OfferStatus::Sent;
        }
        $offer->save();

        return $offer;
    }

    public function withdraw(Membership $actor, MovingOffer $offer): MovingOffer
    {
        $this->assertManageable($actor, $offer);
        $offer->update(['status' => OfferStatus::Withdrawn]);

        return $offer;
    }

    /** Someone with offers.approve lets an offer above the sender's limit go out (§17). */
    public function approve(Membership $actor, MovingOffer $offer): MovingOffer
    {
        if ($offer->establishment_id !== $actor->establishment_id || $offer->status !== OfferStatus::AwaitingApproval) {
            throw new MovingException('offer_not_found', 404);
        }
        $this->access->authorize($actor, 'offers.approve', $offer->branch);
        $this->assertOpen($offer->request);
        $offer->update(['status' => OfferStatus::Sent, 'approved_by_membership_id' => $actor->id]);
        $this->journal->move($offer->request, null, 'offer_received', 'provider', $actor->provider_id, [], ['offer' => $offer->uuid]);

        return $offer;
    }

    /** The branch of this member's establishment the request reached and the member works in. */
    public function reachedBranch(Membership $actor, MovingRequest $request): Branch
    {
        $branchIds = DB::table('moving_request_branches')->where('request_id', $request->id)
            ->where('establishment_id', $actor->establishment_id)->orderBy('distance_km')->pluck('branch_id');
        foreach ($branchIds as $id) {
            $branch = Branch::query()->find($id);
            if ($branch && $this->access->can($actor, 'requests.view', $branch)) {
                return $branch;
            }
        }

        throw new MovingException('request_not_found', 404);
    }

    private function needsApproval(Membership $actor, int $price): bool
    {
        return $actor->offer_limit_minor !== null && $price > $actor->offer_limit_minor
            && ! $this->access->can($actor, 'offers.approve');
    }

    private function validUntil(MovingRequest $request, string $value): CarbonInterface
    {
        $until = Carbon::parse($value);
        $max = $request->policy('offers.max_validity_hours');
        if ($max !== null && $until->gt(now()->addHours((int) $max))) {
            $until = now()->addHours((int) $max);
        }
        // An offer can't outlive the request's offer time.
        if ($request->offers_deadline_at && $until->gt($request->offers_deadline_at)) {
            $until = $request->offers_deadline_at->copy();
        }
        if ($until->isPast()) {
            throw new MovingException('offer_validity_past');
        }

        return $until;
    }

    /** REQ-027: the same vehicle is not promised to two jobs closer than the country's gap. */
    private function assertVehicleFree(MovingRequest $request, Vehicle $vehicle, CarbonInterface $arrival, ?int $ignoreOfferId = null): void
    {
        $gap = $request->policy('schedule.block_hours');
        if ($gap === null) {
            return;
        }
        $busy = MovingRequest::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(MovingStatus::cases(), fn ($s) => $s->isActiveJob())))
            ->whereBetween('scheduled_at', [$arrival->copy()->subHours((int) $gap), $arrival->copy()->addHours((int) $gap)])
            ->first();
        if ($busy !== null) {
            throw new MovingException('vehicle_busy', 409, ['time' => $busy->scheduled_at->toDateTimeString()], ['request' => $busy->uuid]);
        }
    }

    private function assertOpen(MovingRequest $request): void
    {
        if ($request->status !== MovingStatus::Open || ($request->offers_deadline_at && $request->offers_deadline_at->isPast())) {
            throw new MovingException('request_closed', 409);
        }
    }

    private function assertManageable(Membership $actor, MovingOffer $offer): void
    {
        if ($offer->establishment_id !== $actor->establishment_id || ! in_array($offer->status, [OfferStatus::Sent, OfferStatus::AwaitingApproval], true)) {
            throw new MovingException('offer_not_found', 404);
        }
        $this->access->authorize($actor, 'offers.manage', $offer->branch);
    }
}
