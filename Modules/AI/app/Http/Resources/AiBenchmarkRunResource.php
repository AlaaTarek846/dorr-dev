<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiBenchmarkRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'model_key' => $this->model_key,
            'status' => $this->status,
            'total_cases' => $this->total_cases,
            'passed_cases' => $this->passed_cases,
            'abstained_cases' => $this->abstained_cases,
            'pass_rate' => $this->pass_rate !== null ? (float) $this->pass_rate : null,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'results' => AiBenchmarkResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
