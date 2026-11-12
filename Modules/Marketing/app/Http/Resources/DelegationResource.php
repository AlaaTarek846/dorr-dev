<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\Platforms\DelegationService;

class DelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $used = app(DelegationService::class)->used($this->resource);
        $member = MarketingProjectMember::query()->where('project_id', $this->project_id)->where('member_type', $this->delegate_type)->where('member_id', $this->delegate_id)->with('member')->first();

        return [
            'id' => $this->uuid,
            'member_id' => $member?->id,
            'name' => $member?->member?->name,
            'cap_minor' => $this->cap_minor,
            'used_minor' => $used,
            'remaining_minor' => max(0, (int) $this->cap_minor - $used),
            'currency' => $this->currency,
            'status' => $this->status === 'active' && $this->expires_at?->isPast() ? 'expired' : $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
