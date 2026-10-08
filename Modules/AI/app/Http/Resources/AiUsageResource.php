<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'owner' => $this->whenLoaded('request', fn () => $this->request?->owner ? [
                'type' => $this->request->owner_type,
                'id' => $this->request->owner_id,
                'name' => $this->request->owner->name ?? null,
            ] : null),
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'total_tokens' => $this->total_tokens,
            'input_cost' => (float) $this->input_cost,
            'output_cost' => (float) $this->output_cost,
            'total_cost' => (float) $this->total_cost,
            'usage_type' => $this->usage_type,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
