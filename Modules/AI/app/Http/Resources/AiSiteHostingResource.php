<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Services\Sites\AiSiteHostingService;

/** @mixin AiSiteHosting */
class AiSiteHostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->project;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'subdomain' => $this->subdomain,
            'url' => app(AiSiteHostingService::class)->url($this->resource),
            'status' => $this->status,
            'is_live' => $this->isLive(),
            'admin_suspended' => $this->admin_suspended,
            'period' => $this->period,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'auto_renew' => $this->auto_renew,
            'plan' => $this->whenLoaded('plan', fn () => ['id' => $this->plan?->id, 'name' => $this->plan?->name]),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601String(),
            'published_version_id' => $this->published_version_id,
            // The customer edited after publishing: the public still sees the older version.
            'has_unpublished_changes' => $project !== null && $project->current_version_id !== null && $project->current_version_id !== $this->published_version_id,
            'owner' => $this->when(str_starts_with((string) $request->path(), 'admin/'), fn () => [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ]),
            'project_title' => $this->when(str_starts_with((string) $request->path(), 'admin/'), fn () => $project?->title),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
