<?php

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_code' => $this->phone_code,
            'gender' => $this->gender?->value ?? $this->gender,
            'gender_label' => $this->gender?->label() ?? null,
            'country_id' => $this->country_id,
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country?->id,
                'code' => $this->country?->code,
                'dial_code' => $this->country?->dial_code,
                'flag' => $this->country?->flag ? [
                    'id' => $this->country->flag->id,
                    'code' => $this->country->flag->code,
                ] : null,
            ]),
            'avatar' => $this->getSingleMediaUrl('avatar') ?: null,
            'avatar_thumb' => $this->getSingleMediaThumbUrl('avatar') ?: null,
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->status?->label() ?? null,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'phone_verified_at' => $this->phone_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
