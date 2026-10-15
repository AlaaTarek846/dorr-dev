<?php

namespace Modules\Driver\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\Vehicle;

/**
 * A driver in the dashboard. The list shows the summary; with `detail` (the driver's page) it also
 * carries their documents (with the files) and vehicles.
 *
 * @mixin Driver
 */
class AdminDriverResource extends JsonResource
{
    public bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'email' => $this->email,
            'avatar_url' => $this->getFirstMediaUrl('avatar') ?: null,
            'country' => $this->whenLoaded('country', fn () => ['id' => $this->country?->id, 'code' => $this->country?->code, 'name' => $this->country?->translatedName()]),
            'country_id' => $this->country_id,
            'city_id' => $this->city_id,
            'residency' => $this->residency?->value,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'registered_by' => $this->registered_by,
            'presence' => $this->whenLoaded('presence', fn () => $this->presence?->status?->value ?? 'offline'),
            'active_vehicle' => $this->whenLoaded('activeVehicle', fn () => $this->activeVehicle ? self::vehicle($this->activeVehicle) : null),
            'pending_documents_count' => $this->pending_documents_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $this->detail) {
            return $summary;
        }

        $this->resource->loadMissing(['documents.documentType.translations', 'vehicles.make.translations', 'vehicles.model.translations', 'vehicles.color.translations', 'nationality']);

        return $summary + [
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'national_id' => $this->national_id,
            'id_type' => $this->id_type,
            'nationality_country_id' => $this->nationality_country_id,
            'locale' => $this->locale,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'documents' => $this->documents->sortByDesc('id')->values()->map(fn (DriverDocument $d) => [
                'id' => $d->id,
                'type' => ['id' => $d->document_type_id, 'code' => $d->documentType?->code, 'name' => $d->documentType?->translated('name'), 'applies_to' => $d->documentType?->applies_to?->value],
                'vehicle_id' => $d->vehicle_id,
                'number' => $d->number,
                'expires_at' => $d->expires_at?->toDateString(),
                'expired' => $d->expires_at !== null && $d->expires_at->isPast(),
                'status' => $d->status->value,
                'rejection_reason' => $d->rejection_reason,
                'front_url' => $d->getFirstMediaUrl('front') ?: null,
                'back_url' => $d->getFirstMediaUrl('back') ?: null,
                'reviewed_at' => $d->reviewed_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ]),
            'vehicles' => $this->vehicles->sortByDesc('id')->values()->map(fn (Vehicle $v) => self::vehicle($v)),
        ];
    }

    /** @return array<string, mixed> */
    public static function vehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'make_id' => $vehicle->make_id,
            'model_id' => $vehicle->model_id,
            'color_id' => $vehicle->color_id,
            'make' => $vehicle->make?->translated('name'),
            'model' => $vehicle->model?->translated('name'),
            'color' => $vehicle->color?->translated('name'),
            'color_hex' => $vehicle->color?->hex,
            'year' => $vehicle->year,
            'body_type' => $vehicle->body_type?->value,
            'seats' => $vehicle->seats,
            'plate_letters' => $vehicle->plate_letters,
            'plate_number' => $vehicle->plate_number,
            'plate_type' => $vehicle->plate_type,
            'sequence_number' => $vehicle->sequence_number,
            'features' => $vehicle->features ?? [],
            'status' => $vehicle->status->value,
            'is_active' => (bool) $vehicle->is_active,
        ];
    }
}
