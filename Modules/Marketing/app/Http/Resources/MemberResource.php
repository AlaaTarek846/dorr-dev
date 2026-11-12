<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingProjectMember;

/** @mixin MarketingProjectMember */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'name' => $this->member?->name,
            'phone' => $this->member?->phone,
            'is_me' => $request->user() !== null && $this->member_type === $request->user()->getMorphClass() && $this->member_id === $request->user()->getKey(),
            'added_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
