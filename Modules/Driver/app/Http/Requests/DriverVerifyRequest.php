<?php

namespace Modules\Driver\Http\Requests;

class DriverVerifyRequest extends DriverOtpRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))],
        ];
    }
}
