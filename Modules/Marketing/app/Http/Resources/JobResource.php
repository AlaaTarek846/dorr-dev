<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingJob;

/** @mixin MarketingJob */
class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->job_type,
            'status' => $this->status,
            'progress_pct' => $this->progress_pct,
            'error_code' => $this->error_code,
            'retryable' => in_array($this->error_code, ['ai_unavailable', 'internal_error', 'invalid_ai_output'], true),
            'plan_type' => $this->payload['plan_type'] ?? null,
            'created' => $this->payload['created'] ?? null,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
