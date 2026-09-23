<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    public const INCLUDE_PERMISSIONS_ATTRIBUTE = 'admin_include_permissions';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => (bool) $this->status,
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
            'service_category_ids' => $this->whenLoaded('services', fn () => $this->services
                ->pluck('service_category_id')
                ->values()
                ->all()),
            'services' => AdminServiceResource::collection($this->whenLoaded('services')),
            'role_id' => $this->whenLoaded('roles', fn () => $this->roles->first()?->id),
            'role_name' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($this->shouldIncludePermissions($request)) {
            $data['permission_names'] = $this->getAllPermissions()
                ->pluck('name')
                ->values()
                ->all();
        }

        return $data;
    }

    protected function shouldIncludePermissions(Request $request): bool
    {
        return (bool) $request->attributes->get(self::INCLUDE_PERMISSIONS_ATTRIBUTE, false);
    }
}
