<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Same length VerificationCodeService generates.
            'code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))],
        ];
    }
}
