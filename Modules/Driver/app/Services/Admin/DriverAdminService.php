<?php

namespace Modules\Driver\Services\Admin;

use App\Models\Country;
use App\Services\General\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Admin;
use Modules\Driver\Enums\DocumentStatus;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Services\DriverRecords;
use Modules\Driver\Services\PresenceService;

/**
 * Admin → Drivers (docs/rides-plan.md §11.3): register a driver from the dashboard (citizen or
 * resident, any country), keep their details, documents and vehicles, and decide their account
 * status. Every decision is audited. Whether they may work is then the work gate's call
 * (documents for their kind, an active approved vehicle, the regulator where there is one).
 */
class DriverAdminService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PresenceService $presence,
        private readonly DriverRecords $records,
    ) {}

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return Driver::query()
            ->with(['country.translations', 'activeVehicle.make.translations', 'activeVehicle.model.translations', 'activeVehicle.color.translations', 'presence'])
            ->withCount(['documents as pending_documents_count' => fn ($q) => $q->where('status', DocumentStatus::Pending->value)])
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('national_id', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['residency'] ?? null, fn ($q, $residency) => $q->where('residency', $residency))
            ->when($filters['country_id'] ?? null, fn ($q, $country) => $q->where('country_id', $country))
            ->when($filters['registered_by'] ?? null, fn ($q, $by) => $q->where('registered_by', $by))
            ->when(! empty($filters['pending_documents']), fn ($q) => $q->whereHas('documents', fn ($q) => $q->where('status', DocumentStatus::Pending->value)))
            ->latest('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, Admin $admin): Driver
    {
        $country = Country::query()->findOrFail($data['country_id']);
        $phone = self::phone($country, (string) $data['phone']);
        $this->assertPhoneFree($phone);

        return DB::transaction(function () use ($data, $country, $phone, $admin) {
            $driver = Driver::query()->create([
                ...$this->details($data),
                'uuid' => (string) Str::uuid(),
                'phone' => $phone,
                'country_id' => $country->id,
                'status' => DriverStatus::PendingDocuments,
                'registered_by' => 'admin',
                'registered_by_admin_id' => $admin->id,
            ]);
            $this->audit->record('driver.created', $driver, null, $driver->only(['name', 'phone', 'country_id', 'residency', 'national_id']), $admin);

            return $driver;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Driver $driver, array $data, Admin $admin): Driver
    {
        $before = $driver->only([...array_keys($this->details($data)), 'phone', 'country_id']);
        $changes = $this->details($data);
        if (isset($data['phone'])) {
            $phone = self::phone(Country::query()->findOrFail($data['country_id'] ?? $driver->country_id), (string) $data['phone']);
            if ($phone !== $driver->phone) {
                $this->assertPhoneFree($phone, $driver->id);
                $changes['phone'] = $phone;
            }
        }
        if (isset($data['country_id'])) {
            $changes['country_id'] = (int) $data['country_id'];
        }
        $driver->fill($changes)->save();
        $this->audit->record('driver.updated', $driver, $before, $driver->only(array_keys($changes)), $admin);

        return $driver->refresh();
    }

    /** Active / suspended / restricted / blocked…: a driver who may not work any more goes offline at once. */
    public function setStatus(Driver $driver, DriverStatus $status, ?string $reason, Admin $admin): Driver
    {
        $before = ['status' => $driver->status->value, 'status_reason' => $driver->status_reason];
        $driver->forceFill([
            'status' => $status,
            'status_reason' => $status === DriverStatus::Active ? null : $reason,
            'activated_at' => $status === DriverStatus::Active ? ($driver->activated_at ?? now()) : $driver->activated_at,
        ])->save();

        if ($status !== DriverStatus::Active) {
            $this->presence->goOffline($driver, 'admin');
        }
        if ($status === DriverStatus::Blocked) {
            $driver->tokens()->delete();
        }
        $this->audit->record('driver.status_changed', $driver, $before, ['status' => $status->value, 'status_reason' => $driver->status_reason], $admin);
        // O-66: the captain's app hears it at once (real time + push) and reloads — no waiting for a restart.
        $this->tellDriver($driver, 'driver.status', $status === DriverStatus::Active ? 'driver_activated' : 'driver_status_changed', ['reason' => (string) $driver->status_reason]);

        return $driver->refresh();
    }

    /** @param array<string, mixed> $data */
    public function saveVehicle(Driver $driver, array $data, Admin $admin, ?Vehicle $vehicle = null): Vehicle
    {
        $before = $vehicle?->only(DriverRecords::VEHICLE_FIELDS);
        $vehicle = $this->records->saveVehicle($driver, $data, $vehicle);
        $this->audit->record($before === null ? 'driver.vehicle_created' : 'driver.vehicle_updated', $vehicle, $before, $vehicle->only(DriverRecords::VEHICLE_FIELDS), $admin);

        return $vehicle;
    }

    /** Approve / reject / retire a vehicle; approving one the driver has no active vehicle for makes it the active one. */
    public function setVehicleStatus(Vehicle $vehicle, VehicleStatus $status, Admin $admin): Vehicle
    {
        $before = ['status' => $vehicle->status->value, 'is_active' => $vehicle->is_active];
        DB::transaction(function () use ($vehicle, $status, $admin) {
            $vehicle->forceFill([
                'status' => $status,
                'approved_by_admin_id' => $status === VehicleStatus::Approved ? $admin->id : $vehicle->approved_by_admin_id,
                'approved_at' => $status === VehicleStatus::Approved ? now() : $vehicle->approved_at,
                'is_active' => $status === VehicleStatus::Approved && ($vehicle->is_active || ! Vehicle::query()->where('driver_id', $vehicle->driver_id)->where('is_active', true)->exists()),
            ])->save();
        });
        $this->audit->record('driver.vehicle_status', $vehicle, $before, ['status' => $status->value, 'is_active' => $vehicle->is_active], $admin);
        // O-66: the captain's app follows at once — approved (ready to work) or turned down.
        if ($before['status'] !== $status->value && ($owner = Driver::query()->find($vehicle->driver_id)) !== null) {
            match ($status) {
                VehicleStatus::Approved => $this->tellDriver($owner, 'driver.vehicle', 'driver_vehicle_ready', []),
                VehicleStatus::Rejected, VehicleStatus::Inactive => $this->tellDriver($owner, 'driver.vehicle', 'driver_vehicle_stopped', []),
                default => null,
            };
        }

        return $vehicle->refresh();
    }

    /** The vehicle the driver works with now (one at a time). */
    public function activateVehicle(Vehicle $vehicle, Admin $admin): Vehicle
    {
        if ($vehicle->status !== VehicleStatus::Approved) {
            throw ValidationException::withMessages(['vehicle' => __('driver.errors.vehicle_not_approved')]);
        }
        DB::transaction(function () use ($vehicle) {
            Vehicle::query()->where('driver_id', $vehicle->driver_id)->whereKeyNot($vehicle->id)->update(['is_active' => false]);
            $vehicle->forceFill(['is_active' => true])->save();
        });
        $this->audit->record('driver.vehicle_activated', $vehicle, null, ['vehicle_id' => $vehicle->id], $admin);
        if (($owner = Driver::query()->find($vehicle->driver_id)) !== null) {
            $this->tellDriver($owner, 'driver.vehicle', 'driver_vehicle_ready', []);
        }

        return $vehicle->refresh();
    }

    /**
     * A document uploaded or reviewed by the admin. Uploaded from the dashboard it is approved at once
     * unless the admin says otherwise; approving one replaces the earlier approved one of that type.
     *
     * @param  array<string, mixed>  $data
     */
    public function addDocument(Driver $driver, array $data, Admin $admin): DriverDocument
    {
        return DB::transaction(function () use ($driver, $data, $admin) {
            $document = $this->records->addDocument($driver, $data);

            return ($data['approve'] ?? true) ? $this->review($document, true, null, $admin) : $document;
        });
    }

    public function review(DriverDocument $document, bool $approve, ?string $reason, Admin $admin): DriverDocument
    {
        return DB::transaction(function () use ($document, $approve, $reason, $admin) {
            if ($approve) {
                DriverDocument::query()->where('driver_id', $document->driver_id)->where('document_type_id', $document->document_type_id)
                    ->where('vehicle_id', $document->vehicle_id)->where('status', DocumentStatus::Approved->value)->whereKeyNot($document->id)
                    ->update(['status' => DocumentStatus::Replaced->value]);
            }
            $document->forceFill([
                'status' => $approve ? DocumentStatus::Approved : DocumentStatus::Rejected,
                'rejection_reason' => $approve ? null : $reason,
                'reviewed_by_admin_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
            $this->audit->record($approve ? 'driver.document_approved' : 'driver.document_rejected', $document, null, ['document_type_id' => $document->document_type_id, 'reason' => $reason], $admin);
            if (($owner = Driver::query()->find($document->driver_id)) !== null) {
                $this->tellDriver($owner, 'driver.document', $approve ? 'driver_document_approved' : 'driver_document_rejected', ['reason' => (string) $reason]);
            }

            return $document->refresh();
        });
    }

    /** O-66: a change to the captain's account, said to his app at once (real time + push), after the save. */
    private function tellDriver(Driver $driver, string $event, string $key, array $vars): void
    {
        app(\App\Services\Notifications\NotificationCenter::class)->send($driver, $event, $key.'_title', $key.'_body', $vars, ['type' => 'driver_account', 'status' => $driver->status->value]);
    }

    /** "+9665…" from the country's dial code and the local number (a leading 0 dropped). */
    public static function phone(Country $country, string $local): string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $local), '0');
        $dial = ltrim((string) $country->dial_code, '+');

        return '+'.(str_starts_with($digits, $dial) && strlen($digits) > (int) $country->phone_length ? $digits : $dial.$digits);
    }

    private function assertPhoneFree(string $phone, ?int $except = null): void
    {
        if (Driver::withTrashed()->where('phone', $phone)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['phone' => __('driver.errors.phone_taken')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return collect($data)->only([
            'name', 'name_ar', 'name_en', 'email', 'gender', 'date_of_birth', 'national_id', 'id_type',
            'residency', 'nationality_country_id', 'city_id', 'locale',
        ])->all();
    }
}
