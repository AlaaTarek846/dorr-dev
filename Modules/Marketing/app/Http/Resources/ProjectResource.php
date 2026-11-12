<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;

/** @mixin MarketingProject */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $me = $this->relationLoaded('members') && $actor !== null
            ? $this->members->first(fn (MarketingProjectMember $m) => $m->member_type === $actor->getMorphClass() && $m->member_id === $actor->getKey())
            : null;
        $role = $me?->role;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'industry' => $this->industry,
            'stage' => $this->stage,
            'goals' => $this->goals ?? [],
            'cities' => $this->city_names ?? [],
            'currency' => $this->budget_currency,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'my_role' => $role?->value,
            'abilities' => $role instanceof ProjectRole ? $role->abilities() : null,
            'members_count' => $this->relationLoaded('members') ? $this->members->count() : null,
            'profile' => $this->whenLoaded('profile', fn () => [
                'offer' => $this->profile?->offer,
                'customer_segments' => $this->profile?->customer_segments ?? [],
                'channels' => $this->profile?->channels ?? [],
                'team_size' => $this->profile?->team_size,
                'monthly_budget_minor' => $this->profile?->monthly_budget_minor,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
