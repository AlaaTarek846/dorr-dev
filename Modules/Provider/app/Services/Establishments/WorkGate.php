<?php

namespace Modules\Provider\Services\Establishments;

use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Enums\VehicleStatus;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Documents\DocumentRequirements;

/**
 * Who may work (A-08, REQ-007 → REQ-009). Nobody sends an offer without an approved vehicle whose photos and papers
 * are valid, in an approved establishment that offers the service (§6). An individual drives themselves, so their
 * own driving licence must be approved before the first offer; a company's driver is checked when given the job.
 * Each check returns a reason code (null = fine) the apps show as is.
 */
class WorkGate
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly DocumentRequirements $requirements,
    ) {}

    /**
     * Why this member can't offer with this vehicle now, or null. `$branch` is the branch the request reached: sending
     * from it may use any vehicle of the establishment (the trucks move between branches); without it, the vehicle's own.
     */
    public function offerProblem(Membership $actor, Vehicle $vehicle, int $serviceCategoryId, ?Branch $branch = null): ?string
    {
        $establishment = $actor->establishment;

        if (! $establishment->isApproved()) {
            return 'establishment_not_approved';
        }
        if (! $establishment->offersService($serviceCategoryId)) {
            return 'service_off';
        }
        if (! $this->access->can($actor, 'offers.send', $branch ?? $vehicle->branch)) {
            return 'forbidden';
        }
        if ($vehicle->establishment_id !== $establishment->id) {
            return 'vehicle_not_found';
        }
        if ($problem = $this->vehicleProblem($vehicle, $serviceCategoryId)) {
            return $problem;
        }
        if ($this->ownerBoundElsewhere($actor)) {
            return 'exclusive_elsewhere';
        }
        if (EstablishmentService::isIndividual($establishment)) {
            $owner = $establishment->memberships()->where('is_owner', true)->first();
            if ($owner === null || $this->licenceProblem($owner) !== null) {
                return 'driver_licence';
            }
        }

        return null;
    }

    public function vehicleProblem(Vehicle $vehicle, int $serviceCategoryId): ?string
    {
        if ($vehicle->status !== VehicleStatus::Approved) {
            return 'vehicle_not_approved';
        }
        if (! $vehicle->type()->whereHas('services', fn ($q) => $q->whereKey($serviceCategoryId))->exists()) {
            return 'vehicle_wrong_service';
        }
        $notValid = $this->requirements->notValid($vehicle->establishment, DocumentSubject::Vehicle, $vehicle->id, $vehicle->vehicle_type_id);

        return $notValid === [] ? null : 'vehicle_documents';
    }

    /** May this member be given a job to drive? */
    public function driverProblem(Membership $driver): ?string
    {
        if (! $driver->isActive()) {
            return 'driver_inactive';
        }
        if (! $this->access->can($driver, 'driving.work')) {
            return 'not_a_driver';
        }

        return $this->licenceProblem($driver);
    }

    public function licenceProblem(Membership $driver): ?string
    {
        $notValid = $this->requirements->notValid($driver->establishment, DocumentSubject::Driver, $driver->id);

        return $notValid === [] ? null : 'driver_licence';
    }

    /**
     * The owner of this establishment joined another one as exclusive: while that lasts their own establishment
     * sends no offers (§14).
     */
    private function ownerBoundElsewhere(Membership $actor): bool
    {
        $ownerId = $actor->establishment->owner_id;

        return Membership::query()
            ->where('provider_id', $ownerId)
            ->where('establishment_id', '!=', $actor->establishment_id)
            ->where('status', MembershipStatus::Active->value)
            ->where('is_exclusive', true)
            ->exists();
    }
}
