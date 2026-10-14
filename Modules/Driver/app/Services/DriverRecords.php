<?php

namespace Modules\Driver\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Driver\Enums\DocumentStatus;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\Vehicle;

/**
 * A driver's vehicles and documents as written by the driver (app) or the admin (dashboard): the
 * same checks either way — one plate per country, letters kept spaced the way Wasl reads them.
 */
class DriverRecords
{
    public const VEHICLE_FIELDS = ['make_id', 'model_id', 'color_id', 'year', 'body_type', 'seats', 'plate_letters', 'plate_number', 'plate_type', 'sequence_number', 'features'];

    /** What a vehicle may offer (category rules read these). */
    public const VEHICLE_FEATURES = ['child_seat', 'wheelchair_ramp', 'pet_friendly', 'large_luggage'];

    /** @return array<string, mixed> validation rules for a new vehicle, or for editing one */
    public static function vehicleRules(Driver $driver, ?Vehicle $vehicle = null): array
    {
        $required = $vehicle === null ? 'required' : 'sometimes';

        return [
            'make_id' => [$required, 'integer', Rule::exists('vehicle_makes', 'id')],
            'model_id' => [$required, 'integer', Rule::exists('vehicle_models', 'id')],
            'color_id' => [$required, 'integer', Rule::exists('vehicle_colors', 'id')],
            'year' => [$required, 'integer', 'between:1980,'.(now()->year + 1)],
            'body_type' => [$required, Rule::enum(VehicleBodyType::class)],
            'seats' => [$required, 'integer', 'between:1,60'],
            'plate_letters' => [$required, 'string', 'max:8'],
            'plate_number' => [$required, 'string', 'max:8'],
            'plate_type' => [$required, 'integer', 'between:0,65535'],
            'sequence_number' => ['nullable', 'string', 'max:16', Rule::unique('vehicles', 'sequence_number')->where('country_id', $driver->country_id)->ignore($vehicle?->id)],
            'features' => ['nullable', 'array'],
            'features.*' => [Rule::in(self::VEHICLE_FEATURES)],
        ];
    }

    /** @return array<string, mixed> validation rules for a document of this type (its own fields decide what's required) */
    public static function documentRules(Driver $driver, ?DriverDocumentType $type): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('driver_document_types', 'id')->whereNull('deleted_at')->where('status', true)],
            'vehicle_id' => [$type?->applies_to?->value === 'vehicle' ? 'required' : 'nullable', 'integer', Rule::exists('vehicles', 'id')->where('driver_id', $driver->id)],
            'number' => [$type?->has_number ? 'required' : 'nullable', 'string', 'max:64'],
            'expires_at' => [$type?->has_expiry ? 'required' : 'nullable', 'date', 'after:today'],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'back' => [$type?->needs_back_side ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveVehicle(Driver $driver, array $data, ?Vehicle $vehicle = null): Vehicle
    {
        $fields = collect($data)->only(self::VEHICLE_FIELDS)->all();
        if (isset($fields['plate_letters'])) {
            $fields['plate_letters'] = implode(' ', mb_str_split((string) preg_replace('/\s+/u', '', $fields['plate_letters'])));
        }

        $vehicle ??= new Vehicle(['uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $driver->country_id, 'status' => VehicleStatus::Pending]);
        $plateTaken = Vehicle::query()->where('country_id', $vehicle->country_id)
            ->where('plate_letters', $fields['plate_letters'] ?? $vehicle->plate_letters)
            ->where('plate_number', $fields['plate_number'] ?? $vehicle->plate_number)
            ->where('plate_type', $fields['plate_type'] ?? $vehicle->plate_type)
            ->when($vehicle->exists, fn ($q) => $q->whereKeyNot($vehicle->id))->exists();
        if ($plateTaken) {
            throw ValidationException::withMessages(['plate_number' => __('driver.errors.plate_taken')]);
        }
        $vehicle->fill($fields)->save();

        return $vehicle->refresh();
    }

    /**
     * A new document waiting for review, with its files (`front`, optional `back`).
     *
     * @param  array<string, mixed>  $data
     */
    public function addDocument(Driver $driver, array $data): DriverDocument
    {
        $document = DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'document_type_id' => $data['document_type_id'],
            'number' => $data['number'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'status' => DocumentStatus::Pending,
        ]);
        $document->setSingleMedia('front', $data['front'] ?? null);
        $document->setSingleMedia('back', $data['back'] ?? null);

        return $document;
    }
}
