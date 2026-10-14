<?php

namespace Modules\Driver\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Driver\Models\Driver;

/**
 * The driver as their own app sees them. `status_reason` is shown as-is (written safely by
 * the admin or by Wasl's rejection codes, D-9).
 *
 * @mixin Driver
 */
class DriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'country_id' => $this->country_id,
            'residency' => $this->residency?->value,
            'city_id' => $this->city_id,
            'locale' => $this->locale,
            'status' => $this->status?->value,
            'status_reason' => $this->status_reason,
            'registered_by' => $this->registered_by,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'onboarding_ends_at' => $this->onboarding_ends_at?->toIso8601String(),
            'avatar_url' => $this->getFirstMediaUrl('avatar') ?: null,
        ];
    }
}
