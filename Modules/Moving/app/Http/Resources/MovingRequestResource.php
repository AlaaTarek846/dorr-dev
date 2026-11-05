<?php

namespace Modules\Moving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Requests\MovingRequestService;
use Modules\Provider\Http\Resources\App\VehicleTypeResource;

/**
 * A move request. The customer sees all of it. A provider sees it as `provider_view` until its own offer is accepted:
 * the neighbourhoods only — no exact address, no phone (REQ-019, REQ-097) — then everything they need to do the job.
 *
 * @mixin MovingRequest
 */
class MovingRequestResource extends JsonResource
{
    private bool $masked = false;

    private bool $hideMoney = false;

    public function masked(bool $masked = true): static
    {
        $this->masked = $masked;

        return $this;
    }

    /** A driver works the job and doesn't see what it costs (docs/moving_business.md §5). */
    public function withoutMoney(bool $hide = true): static
    {
        $this->hideMoney = $hide;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $end = fn (string $end) => [
            'lat' => $this->masked ? null : $this->{"{$end}_lat"},
            'lng' => $this->masked ? null : $this->{"{$end}_lng"},
            'address' => $this->masked ? null : $this->{"{$end}_address"},
            'area' => $this->{"{$end}_area"},
            'floor' => $this->{"{$end}_floor"},
            'elevator' => $this->{"{$end}_elevator"},
            'entrance' => $this->masked ? null : $this->{"{$end}_entrance"},
        ];

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'load_type' => $this->load_type?->value,
            'description' => $this->description,
            'pieces_count' => $this->pieces_count,
            'dimensions' => $this->dimensions,
            'vehicle_type' => $this->whenLoaded('vehicleType', fn () => $this->vehicleType ? new VehicleTypeResource($this->vehicleType) : null),
            'vehicle_type_id' => $this->vehicle_type_id,
            'workers_count' => $this->workers_count,
            'extras' => ['packing' => $this->packing, 'disassembly' => $this->disassembly, 'crane' => $this->crane, 'technician' => $this->technician],
            'pickup' => $end('pickup'),
            'dropoff' => $end('dropoff'),
            'distance_km' => $this->pickup_lat !== null && $this->dropoff_lat !== null
                ? round(MovingRequestService::km($this->pickup_lat, $this->pickup_lng, $this->dropoff_lat, $this->dropoff_lng), 1) : null,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'notes' => $this->notes,
            'photos' => $this->getMedia('photos')->map(fn ($m) => ['id' => $m->id, 'url' => $m->getUrl()])->values(),
            'videos' => $this->getMedia('videos')->map(fn ($m) => ['id' => $m->id, 'url' => $m->getUrl()])->values(),
            'policy_version' => $this->policy_version,
            'offers_deadline_at' => $this->offers_deadline_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'currency_code' => $this->currency_code,
            'currency_decimals' => (int) ($this->country?->currency?->decimal_places ?? 2),
            'branch_id' => $this->branch_id,
            'money' => $this->price_minor === null || $this->hideMoney ? null : [
                'price_minor' => $this->price_minor,
                'tax_minor' => $this->tax_minor,
                'total_minor' => $this->total_minor,
                'payment_status' => $this->payment_status,
            ],
            'establishment' => $this->whenLoaded('establishment', fn () => $this->establishment ? [
                'uuid' => $this->establishment->uuid, 'name' => $this->establishment->name, 'type' => $this->establishment->type->value,
                'logo' => $this->establishment->getSingleMediaUrl('logo') ?: null,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'uuid' => $this->vehicle->uuid, 'plate_letters' => $this->vehicle->plate_letters, 'plate_number' => $this->vehicle->plate_number,
                'make' => $this->vehicle->make, 'model' => $this->vehicle->model, 'color' => $this->vehicle->color,
            ] : null),
            'driver_accepted' => $this->driver_accepted_at !== null,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver?->provider ? [
                'uuid' => $this->driver->uuid, 'name' => $this->driver->provider->name,
                'avatar' => $this->driver->provider->getSingleMediaUrl('avatar') ?: null,
                'accepted' => $this->driver_accepted_at !== null,
            ] : null),
        ];
    }
}
