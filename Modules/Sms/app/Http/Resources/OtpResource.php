<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OtpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enabled' => (bool) $this->enabled,
            'preferred_channel' => $this->preferred_channel,
            'fallback_channel' => $this->fallback_channel,
            'otp_length' => $this->otp_length,
            'expiration_minutes' => $this->expiration_minutes,
            'resend_cooldown_seconds' => $this->resend_cooldown_seconds,
            'max_attempts' => $this->max_attempts,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
