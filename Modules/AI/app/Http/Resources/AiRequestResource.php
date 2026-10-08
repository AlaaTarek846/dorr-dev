<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ] : null),
            'gateway' => $this->whenLoaded('gateway', fn () => $this->gateway ? [
                'id' => $this->gateway->id,
                'name' => $this->gateway->name,
            ] : null),
            'intent' => $this->whenLoaded('intent', fn () => $this->intent ? [
                'id' => $this->intent->id,
                'key' => $this->intent->key,
                'name' => $this->intent->name,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'key' => $this->provider->key,
                'name' => $this->provider->name,
            ] : null),
            'model_key' => $this->model_key,
            'prompt' => $this->prompt,
            'correlation_id' => $this->correlation_id,
            'idempotency_key' => $this->idempotency_key,
            'retry_count' => $this->retry_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'status' => $this->status,
            'response' => $this->whenLoaded('response', fn () => $this->response ? [
                'response' => $this->response->response,
                'finish_reason' => $this->response->finish_reason,
            ] : null),
            'usage' => $this->whenLoaded('usage', fn () => $this->usage ? [
                'total_tokens' => $this->usage->total_tokens,
                'total_cost' => $this->usage->total_cost,
                'usage_type' => $this->usage->usage_type,
            ] : null),
            'citations' => $this->whenLoaded('citations', fn () => $this->citations->map(fn ($citation) => [
                'id' => $citation->id,
                'source_name' => $citation->knowledgeSource?->name,
                'position' => $citation->position,
                'excerpt' => $citation->excerpt,
                'relevance_score' => $citation->relevance_score,
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
