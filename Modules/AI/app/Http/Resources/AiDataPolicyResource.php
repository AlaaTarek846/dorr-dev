<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiDataPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'data_classification' => $this->data_classification,
            'retention_days' => $this->retention_days,
            'consent_required' => (bool) $this->consent_required,
            'minimization_enabled' => (bool) $this->minimization_enabled,
            'external_provider_allowed' => (bool) $this->external_provider_allowed,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
