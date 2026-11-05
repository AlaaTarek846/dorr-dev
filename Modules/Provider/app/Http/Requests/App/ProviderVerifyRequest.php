<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Validation\Rule;

class ProviderVerifyRequest extends ProviderOtpRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ];
    }
}
