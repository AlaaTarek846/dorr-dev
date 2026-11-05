<?php

namespace Modules\Provider\Services\Vehicles;

use Modules\Provider\Enums\VehicleStatus;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Models\VehicleType;
use Modules\Provider\Services\Establishments\EstablishmentAccess;

/**
 * An establishment's vehicles (A-06, §6). A vehicle is added with its type and plate, then its photos (one showing
 * the plate, plus the angles the admin asks for) and papers; the admin approves it before it can be used in an offer.
 * Editing an approved vehicle sends it back to review, like the captains' vehicles.
 */
class VehicleService
{
    private const FIELDS = ['branch_id', 'vehicle_type_id', 'plate_letters', 'plate_number', 'make', 'model', 'color', 'year'];

    public function __construct(private readonly EstablishmentAccess $access) {}

    /** @param array<string, mixed> $data */
    public function create(Membership $actor, array $data): Vehicle
    {
        $branch = $this->branch($actor, $data['branch_id'] ?? null);
        $this->access->authorize($actor, 'fleet.vehicles', $branch);
        $establishment = $actor->establishment;
        $this->assertType($establishment->country_id, (int) $data['vehicle_type_id']);
        $this->assertPlateFree($establishment->country_id, $data);

        return $establishment->vehicles()->create([
            ...array_intersect_key($data, array_flip(self::FIELDS)),
            'branch_id' => $branch?->id,
            'country_id' => $establishment->country_id,
            'status' => VehicleStatus::Pending,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Membership $actor, Vehicle $vehicle, array $data): Vehicle
    {
        $this->assertOwnVehicle($actor, $vehicle);
        $this->access->authorize($actor, 'fleet.vehicles', $vehicle->branch);
        if (array_key_exists('branch_id', $data)) {
            $branch = $this->branch($actor, $data['branch_id']);
            $this->access->authorize($actor, 'fleet.vehicles', $branch);
        }
        if (isset($data['vehicle_type_id'])) {
            $this->assertType($vehicle->country_id, (int) $data['vehicle_type_id']);
        }
        $this->assertPlateFree($vehicle->country_id, [...$vehicle->only(['plate_letters', 'plate_number']), ...$data], $vehicle->id);

        $vehicle->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        // Moving it between branches is housekeeping; anything else about the vehicle is looked at again.
        if ($vehicle->isDirty(['vehicle_type_id', 'plate_letters', 'plate_number', 'make', 'model', 'color', 'year'])
            && $vehicle->status === VehicleStatus::Approved) {
            $vehicle->status = VehicleStatus::Pending;
        }
        $vehicle->save();

        return $vehicle;
    }

    public function deactivate(Membership $actor, Vehicle $vehicle): Vehicle
    {
        $this->assertOwnVehicle($actor, $vehicle);
        $this->access->authorize($actor, 'fleet.vehicles', $vehicle->branch);
        $vehicle->update(['status' => VehicleStatus::Inactive]);

        return $vehicle;
    }

    public function approve(Vehicle $vehicle, int $adminId): Vehicle
    {
        $vehicle->update(['status' => VehicleStatus::Approved, 'status_reason' => null, 'reviewed_by_admin_id' => $adminId, 'reviewed_at' => now()]);

        return $vehicle;
    }

    public function reject(Vehicle $vehicle, string $reason, int $adminId): Vehicle
    {
        $vehicle->update(['status' => VehicleStatus::Rejected, 'status_reason' => $reason, 'reviewed_by_admin_id' => $adminId, 'reviewed_at' => now()]);

        return $vehicle;
    }

    public function assertOwnVehicle(Membership $actor, Vehicle $vehicle): void
    {
        if ($vehicle->establishment_id !== $actor->establishment_id) {
            throw new ProviderException('not_found', 404);
        }
    }

    private function branch(Membership $actor, mixed $branchId): ?Branch
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return Branch::query()->where('establishment_id', $actor->establishment_id)->find($branchId)
            ?? throw new ProviderException('not_found', 404);
    }

    private function assertType(int $countryId, int $typeId): void
    {
        $ok = VehicleType::query()->whereKey($typeId)->where('status', true)
            ->whereHas('countries', fn ($q) => $q->whereKey($countryId))->exists();
        if (! $ok) {
            throw new ProviderException('vehicle_type_unavailable');
        }
    }

    /** @param array<string, mixed> $data */
    private function assertPlateFree(int $countryId, array $data, ?int $ignoreId = null): void
    {
        $taken = Vehicle::query()
            ->where('country_id', $countryId)
            ->where('plate_number', $data['plate_number'] ?? null)
            ->where('plate_letters', $data['plate_letters'] ?? null)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            throw new ProviderException('plate_taken');
        }
    }
}
