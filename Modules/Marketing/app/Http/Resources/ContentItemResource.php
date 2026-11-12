<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Services\Content\ClaimsReviewer;

class ContentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'format' => $this->format,
            'title' => $this->title,
            'objective' => $this->objective,
            'variants' => $this->content['variants'] ?? [],
            'selected' => $this->content['selected'] ?? null,
            'claims' => $this->claims ?? [],
            'has_blocking_issues' => ClaimsReviewer::blocking($this->claims ?? []),
            'status' => $this->status,
            'planned_at' => $this->planned_at?->toIso8601String(),
            'cost_estimate_minor' => $this->cost_estimate_minor,
            'origin' => $this->origin,
            'plan_id' => $this->plan?->uuid,
            'review_note' => $this->review_note,
            'published_url' => $this->published_url,
            'done_at' => $this->done_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
