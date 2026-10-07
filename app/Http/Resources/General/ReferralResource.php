<?php

namespace App\Http\Resources\General;

use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Referral */
class ReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $code = $this->referralCode;

        return [
            'id' => $this->id,
            'referral_code' => $code?->code,
            'referral_code_id' => $this->referral_code_id,
            'referrer' => ReferralPartyResource::make($this->referrer_type, $this->referrer_id, $this->referrer()),
            'referred' => ReferralPartyResource::make($this->referred_type, $this->referred_id, $this->referred()),
            'status' => $this->status->value,
            'registered_at' => $this->registered_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
