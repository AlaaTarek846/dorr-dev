<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhoneChangeVerifyRequest extends FormRequest
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
        $codeLength = max(4, (int) config('auth_flow.otp_length', 4));

        return [
            'code' => ['required', 'string', 'size:'.$codeLength],
        ];
    }
}
