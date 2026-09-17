<?php

namespace Modules\Provider\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProviderVerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'flow_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 6))],
        ];
    }
}
