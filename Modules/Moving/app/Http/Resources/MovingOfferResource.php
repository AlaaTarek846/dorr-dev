<?php

namespace Modules\Moving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Services\Requests\MovingAfterService;
use Modules\Provider\Enums\DocumentKind;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Http\Resources\App\VehicleTypeResource;
use Modules\Provider\Services\Documents\DocumentRequirements;

/**
 * An offer as the customer compares it (REQ-022, REQ-099, REQ-116): price and currency, what it includes, arrival,
 * trips, workers, technician, terms, the promise after the move, how long it holds, who sends it (establishment and
 * branch) and the vehicle with its approved photos. `reasons` say why it sits where it does (REQ-023).
 *
 * @mixin MovingOffer
 */
class MovingOfferResource extends JsonResource
{
    /** @var list<string> */
    public array $reasons = [];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle;
        $photos = $vehicle ? app(DocumentRequirements::class)->latest(DocumentSubject::Vehicle, $vehicle->id)->load('type')
            ->filter(fn ($d) => $d->type?->kind === DocumentKind::Photo && $d->isValid())
            ->map(fn ($d) => $d->getSingleMediaUrl('front') ?: null)->filter()->values() : collect();

        return [
            'uuid' => $this->uuid,
            'request_uuid' => $this->request?->uuid,
            'status' => $this->status->value,
            'price_minor' => $this->price_minor,
            'currency_code' => $this->currency_code,
            'workers_count' => $this->workers_count,
            'trips_count' => $this->trips_count,
            'includes' => $this->includes ?? (object) [],
            'technician_note' => $this->technician_note,
            'arrival_at' => $this->arrival_at->toIso8601String(),
            'terms' => $this->terms,
            'after_service' => $this->after_service,
            'valid_until' => $this->valid_until->toIso8601String(),
            'establishment' => [
                'uuid' => $this->establishment?->uuid,
                'name' => $this->establishment?->name,
                'type' => $this->establishment?->type?->value,
                'logo' => $this->establishment?->getSingleMediaUrl('logo') ?: null,
                ...($this->establishment ? MovingAfterService::reputation($this->establishment->id) : []),
            ],
            'branch' => $this->branch?->name,
            'vehicle' => $vehicle ? [
                'type' => $vehicle->type ? new VehicleTypeResource($vehicle->type) : null,
                'photos' => $photos,
            ] : null,
            'reasons' => $this->reasons,
        ];
    }
}
