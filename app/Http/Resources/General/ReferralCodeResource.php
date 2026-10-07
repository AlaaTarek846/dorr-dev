<?php

namespace App\Http\Resources\General;

use App\Enums\ReferralStatus;
use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReferralCode */
class ReferralCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'code' => $this->code,
            'owner' => ReferralPartyResource::make($this->referrable_type, $this->referrable_id, $this->owner()),
            'is_active' => $this->is_active,
            'referrals_count' => $this->referrals_count ?? $this->referrals()->count(),
            'created_at' => $this->created_at?->toISOString(),
        ];

        if ($this->relationLoaded('referrals')) {
            $payload['stats'] = [
                'total' => $this->referrals->count(),
                'registered' => $this->referrals->where('status', ReferralStatus::Registered)->count(),
                'completed' => $this->referrals->where('status', ReferralStatus::Completed)->count(),
                'cancelled' => $this->referrals->where('status', ReferralStatus::Cancelled)->count(),
            ];
            $payload['referrals'] = ReferralResource::collection($this->referrals);
        }

        return $payload;
    }
}
