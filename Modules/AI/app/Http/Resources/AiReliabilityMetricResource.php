<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiReliabilityMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'model_key' => $this->model_key,
            'intent' => $this->whenLoaded('intent', fn () => $this->intent ? [
                'id' => $this->intent->id,
                'key' => $this->intent->key,
                'name' => $this->intent->name,
            ] : null),
            'region' => $this->region,
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ] : null),
            'period_start' => $this->period_start?->toISOString(),
            'period_end' => $this->period_end?->toISOString(),
            'success_rate' => (float) $this->success_rate,
            'error_rate' => (float) $this->error_rate,
            'latency_p50_ms' => $this->latency_p50_ms,
            'latency_p95_ms' => $this->latency_p95_ms,
            'latency_p99_ms' => $this->latency_p99_ms,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
