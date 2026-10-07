<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiBenchmarkResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case' => $this->whenLoaded('case', fn () => [
                'id' => $this->case?->id,
                'domain_key' => $this->case?->domain_key,
                'difficulty' => $this->case?->difficulty,
                'prompt' => $this->case?->prompt,
                'expected_behavior' => $this->case?->expected_behavior,
            ]),
            'actual_response' => $this->actual_response,
            'abstained' => (bool) $this->abstained,
            'citation_present' => (bool) $this->citation_present,
            'correctness_score' => $this->correctness_score !== null ? (float) $this->correctness_score : null,
            'passed' => (bool) $this->passed,
            'latency_ms' => $this->latency_ms,
            'estimated_cost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
