<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\Membership;
use Modules\Provider\Services\Establishments\EstablishmentAccess;

/**
 * A seat in an establishment: what the app needs to show the right screens (§17) and the team list.
 *
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $access = app(EstablishmentAccess::class);

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'is_owner' => $this->is_owner,
            'is_exclusive' => $this->is_exclusive,
            'all_branches' => $this->all_branches,
            'branch_ids' => $this->all_branches ? null : $this->branches()->pluck('provider_branches.id')->all(),
            'offer_limit_minor' => $this->offer_limit_minor,
            'permissions' => $access->permissions($this->resource),
            'phone' => $this->phone,
            'person' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'name' => $this->provider->name,
                'phone' => $this->provider->phone,
                'avatar' => $this->provider->getSingleMediaUrl('avatar') ?: null,
            ] : null),
            'establishment' => $this->whenLoaded('establishment', fn () => [
                'uuid' => $this->establishment->uuid,
                'name' => $this->establishment->name,
                'type' => $this->establishment->type->value,
                'status' => $this->establishment->status->value,
                'logo' => $this->establishment->getSingleMediaUrl('logo') ?: null,
            ]),
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
