<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiDomainPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_key' => $this->domain_key,
            'name' => $this->name,
            'description' => $this->description,
            'risk_level' => $this->risk_level,
            'requires_jurisdiction' => (bool) $this->requires_jurisdiction,
            'requires_triage' => (bool) $this->requires_triage,
            'sandbox_required' => (bool) $this->sandbox_required,
            'allowlist_enforced' => (bool) $this->allowlist_enforced,
            'system_prompt_addition' => $this->system_prompt_addition,
            'disclaimer_text' => $this->disclaimer_text,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
