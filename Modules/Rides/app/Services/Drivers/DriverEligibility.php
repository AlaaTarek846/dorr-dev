<?php

namespace Modules\Rides\Services\Drivers;

use App\Models\ServiceCategory;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Enums\DocumentStatus;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\EligibilityStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Services\DocumentRequirements;
use Modules\Rides\Enums\RegulatorStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\DriverCategoryEligibility;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideCategory;

/**
 * Whether a driver may work in passenger rides, and take a given ride (docs/rides-plan.md §8, §11.4):
 * an active account, the rides service not refused, an approved active vehicle, every required
 * document approved and in date, and — in a country with a regulator — its approval, still valid,
 * for that vehicle. Per category: the vehicle and driver fit the category's rules and the driver
 * asked for it. Every "no" comes back as a reason code the app shows (D-9, D-27).
 */
class DriverEligibility implements DriverWorkGate
{
    public function reasonNotToWork(Driver $driver): ?string
    {
        if ($driver->status !== DriverStatus::Active) {
            return 'account_'.$driver->status->value;
        }

        $service = $this->service();
        $serviceRow = $service ? $driver->serviceEligibility()->where('service_category_id', $service->id)->first() : null;
        if ($serviceRow !== null && $serviceRow->status !== EligibilityStatus::Eligible) {
            return 'service_'.$serviceRow->status->value;
        }

        $vehicle = $this->activeVehicle($driver);
        if ($vehicle === null) {
            return 'no_active_vehicle';
        }

        if (($documents = $this->documentsProblem($driver, $vehicle, $service?->id)) !== null) {
            return $documents;
        }

        return $this->regulatorProblem($driver, $vehicle) ?? (app(DrivingHours::class)->blocked($driver) ? 'driving_limit' : null) ?? $this->stateProblem($driver) ?? $this->termsProblem($driver);
    }

    /** D-329, D-336, D-344 — after what the captain can fix themselves: maintenance, the service off where they work, an emergency review. */
    private function stateProblem(Driver $driver): ?string
    {
        $states = app(DriverServiceStatus::class);

        return match (true) {
            $states->maintenance($driver) => 'maintenance',
            $states->serviceOff($driver) => 'service_off_here',
            $states->emergencyReview($driver) !== null => 'emergency_review',
            default => null,
        };
    }

    /** D-318: where the country asks for it, newer terms than the captain accepted keep them offline until they accept. */
    private function termsProblem(Driver $driver): ?string
    {
        if ($driver->country_id === null || ! app(\Modules\Rides\Services\Policy\RidePolicies::class)->for((int) $driver->country_id)->bool('drivers.require_terms')) {
            return null;
        }

        return collect(app(DriverAccount::class)->legal($driver))->contains('needs_acceptance', true) ? 'terms_pending' : null;
    }

    public function activeRide(Driver $driver): ?array
    {
        $lock = RideActiveLock::query()->find(RideActiveLock::forDriver($driver->id));
        if ($lock === null) {
            return null;
        }
        $status = Ride::query()->whereKey($lock->ride_id)->value('status');

        return ['id' => (int) $lock->ride_id, 'has_customer' => $status === RideStatus::InProgress->value];
    }

    /** May this driver, with this vehicle, take a ride in this category? Null = yes, else a reason code. */
    public function categoryProblem(Driver $driver, Vehicle $vehicle, RideCategory $category): ?string
    {
        // O-85: the right kind of vehicle first — a bus never gets Economy, a car never Motorcycle.
        if (! VehicleKinds::fits($vehicle, $category)) {
            return 'vehicle_kind';
        }
        $row = DriverCategoryEligibility::query()->where('driver_id', $driver->id)->where('category_id', $category->id)->first();
        if ($row !== null && $row->admin_override) {
            return $row->status === 'eligible' ? null : 'category_'.$row->status;
        }
        if ($row !== null && in_array($row->status, ['suspended', 'ineligible'], true)) {
            return 'category_'.$row->status;
        }

        $rules = (array) $category->eligibility;
        $vehicle->loadMissing('model');

        return match (true) {
            isset($rules['body_types']) && ! in_array($vehicle->body_type->value, (array) $rules['body_types'], true) => 'vehicle_body_type',
            isset($rules['min_year']) && $vehicle->year < (int) $rules['min_year'] => 'vehicle_too_old',
            isset($rules['min_seats']) && $vehicle->seats < (int) $rules['min_seats'] => 'vehicle_seats',
            ! empty($rules['luxury_only']) && ! $vehicle->model?->is_luxury => 'vehicle_not_luxury',
            collect((array) ($rules['required_features'] ?? []))->contains(fn (string $f) => ! $vehicle->hasFeature($f)) => 'vehicle_features',
            isset($rules['driver_gender']) && $driver->gender?->value !== $rules['driver_gender'] => 'driver_gender',
            // D-254: VIP (or any category) may ask for a rank.
            ! empty($rules['min_rank']) && ! app(DriverRanks::class)->reaches($driver, (string) $rules['min_rank']) => 'driver_rank',
            default => null,
        };
    }

    /** Did the driver switch this category off in their preferences (D-32)? */
    public function wants(Driver $driver, RideCategory $category): bool
    {
        $wanted = DriverPreference::query()->whereKey($driver->id)->value('categories');
        $list = is_string($wanted) ? json_decode($wanted, true) : $wanted;

        return ! is_array($list) || in_array($category->slug, $list, true);
    }

    public function activeVehicle(Driver $driver): ?Vehicle
    {
        return $driver->vehicles()->where('is_active', true)->where('status', VehicleStatus::Approved->value)->first();
    }

    private function documentsProblem(Driver $driver, Vehicle $vehicle, ?int $serviceId): ?string
    {
        foreach (app(DocumentRequirements::class)->for($driver, $serviceId === null ? null : config('rides.service_module_name')) as $type) {
            $document = DriverDocument::query()
                ->where('driver_id', $driver->id)
                ->where('document_type_id', $type->id)
                ->where('status', DocumentStatus::Approved->value)
                ->when($type->applies_to->value === 'vehicle', fn ($q) => $q->where('vehicle_id', $vehicle->id))
                ->latest('id')
                ->first();

            if ($document === null) {
                return 'documents_missing';
            }
            if ($type->has_expiry && $document->expires_at !== null && $document->expires_at->isPast()) {
                return 'documents_expired';
            }
        }

        return null;
    }

    private function regulatorProblem(Driver $driver, Vehicle $vehicle): ?string
    {
        $regulator = $this->regulatorFor($driver);
        if ($regulator === null || ! config('rides.regulator_enforced', true)) {
            return null;
        }

        $status = DriverRegulatorStatus::query()->where('driver_id', $driver->id)->where('regulator', $regulator)->first();
        if ($status === null) {
            return 'regulator_pending';
        }
        foreach (['driver_status', 'vehicle_status'] as $field) {
            if ($status->{$field} !== RegulatorStatus::Valid->value) {
                return 'regulator_'.$status->{$field};
            }
        }
        if ((int) $status->vehicle_id !== (int) $vehicle->id) {
            return 'regulator_vehicle_changed';
        }
        foreach (['driver_expires_at', 'vehicle_expires_at'] as $field) {
            if ($status->{$field} !== null && $status->{$field}->isPast()) {
                return 'regulator_expired';
            }
        }

        return null;
    }

    public function regulatorFor(Driver $driver): ?string
    {
        $code = $driver->country()->value('code');

        return config('rides.regulators.'.$code);
    }

    private function service(): ?ServiceCategory
    {
        return once(fn () => ServiceCategory::query()->where('module_name', config('rides.service_module_name'))->first());
    }
}
