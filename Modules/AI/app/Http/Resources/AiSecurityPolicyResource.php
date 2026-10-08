<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSecurityPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'authentication_required' => (bool) $this->authentication_required,
            'authorization_required' => (bool) $this->authorization_required,
            'tenant_isolation_required' => (bool) $this->tenant_isolation_required,
            'rate_limit_enabled' => (bool) $this->rate_limit_enabled,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
