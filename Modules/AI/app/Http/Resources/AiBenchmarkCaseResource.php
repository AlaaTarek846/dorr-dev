<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI benchmark case - the same keys as before; `is_active` is kept next to the catalog's `status`;
 * `results_count` = how many past run results it has (deleting it for good is blocked while there are any).
 */
class AiBenchmarkCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_key' => $this->domain_key,
            'country_code' => $this->country_code,
            'language' => $this->language,
            'task_type' => $this->task_type,
            'difficulty' => $this->difficulty,
            'risk_level' => $this->risk_level,
            'prompt' => $this->prompt,
            'expected_behavior' => $this->expected_behavior,
            'expected_answer_keywords' => $this->expected_answer_keywords ?? [],
            'notes' => $this->notes,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'results_count' => $this->whenCounted('results'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
