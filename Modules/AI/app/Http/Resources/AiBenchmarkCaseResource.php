<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
