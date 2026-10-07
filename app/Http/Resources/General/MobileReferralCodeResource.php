<?php

namespace App\Http\Resources\General;

use App\Models\Referral;
use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReferralCode */
class MobileReferralCodeResource extends JsonResource
{
    public function __construct(ReferralCode $code, private readonly ?Referral $applied = null)
    {
        parent::__construct($code);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'is_active' => $this->is_active,
            'applied' => $this->applied === null ? null : [
                'code' => $this->applied->referralCode?->code,
                'status' => $this->applied->status->value,
            ],
        ];
    }
}
