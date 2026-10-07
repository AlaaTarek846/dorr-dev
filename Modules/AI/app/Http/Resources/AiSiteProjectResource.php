<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSiteProject;

/** @mixin AiSiteProject */
class AiSiteProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $live = $this->current_version_id !== null && ! $this->isDisabled();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'access_type' => $this->access_type,
            'preview_url' => $live ? rtrim(route('ai-sites.show', ['slug' => $this->slug]), '/').'/' : null,
            'is_disabled' => $this->isDisabled(),
            'brief' => $this->brief,
            'last_error' => $this->last_error,
            'last_error_message' => $this->last_error && \Illuminate\Support\Facades\Lang::has('ai.site_'.$this->last_error) ? __('ai.site_'.$this->last_error) : null,
            'current_version_id' => $this->current_version_id,
            'generations_left' => $this->whenLoaded('purchase', fn () => $this->purchase?->generationsLeft()),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->sortByDesc('number')->values()->map(fn ($v) => [
                'id' => $v->id,
                'number' => $v->number,
                'kind' => $v->kind,
                'status' => $v->status,
                'instruction' => $v->instruction,
                'error_message' => $v->error_message,
                'total_bytes' => $v->total_bytes,
                'is_current' => $v->id === $this->current_version_id,
                'completed_at' => $v->completed_at?->toIso8601String(),
                'created_at' => $v->created_at?->toIso8601String(),
            ])),
            'owner' => $this->when($request->routeIs('admin.*') || str_starts_with((string) $request->path(), 'admin/'), fn () => [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
