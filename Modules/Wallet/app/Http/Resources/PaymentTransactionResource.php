<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing view of a top-up. Never exposes gateway_context, raw
 * request/response or credentials — the admin screen has its own resource.
 */
class PaymentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'payment_method_id' => $this->payment_method_id,
            'amount_minor' => $this->requested_amount_minor,
            'fee_minor' => $this->quotedFeeMinor(),
            'bonus_minor' => (int) $this->quoted_bonus_amount_minor,
            'net_withdrawable_minor' => $this->quoted_net_amount_minor,
            'redirect_url' => $this->redirect_url,
            // URPay has no redirect page: the customer types the OTP instead.
            'requires_otp' => $this->paymentMethod?->gateway === 'urpay' && $this->status->value === 'pending',
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
