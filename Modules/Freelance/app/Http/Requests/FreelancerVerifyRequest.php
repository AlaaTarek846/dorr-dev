<?php

namespace Modules\Freelance\Http\Requests;

use Illuminate\Validation\Rule;

/** The code, and which device is signing in (one session per device). */
class FreelancerVerifyRequest extends FreelancerOtpRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))],
            'device_id' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ];
    }
}
