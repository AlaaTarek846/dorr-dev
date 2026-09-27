<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\RecoveryMethod;

/**
 * Input for choosing a PIN recovery method, confirming its e-mail, and using it. What is required
 * depends on the method picked (setup) or the method on file (recover — checked in the controller,
 * because it needs the owner's record).
 */
class WalletRecoveryRequest extends FormRequest
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
        $photo = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return match ($this->route()->getActionMethod()) {
            'setup' => [
                'method' => ['required', Rule::enum(RecoveryMethod::class)],
                'password' => ['required_if:method,password', 'nullable', 'string', 'min:6', 'max:100', 'confirmed'],
                'birth_date' => ['required_if:method,birth_date', 'nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
                'email' => ['required_if:method,email', 'nullable', 'email:rfc', 'max:191'],
                'document' => [Rule::requiredIf(fn () => in_array($this->input('method'), ['id_photo', 'passport_photo'], true)), 'nullable', ...$photo],
            ],
            'confirmEmail' => ['code' => ['required', 'string', 'size:'.$codeLength]],
            'recover' => [
                'password' => ['nullable', 'string', 'max:100'],
                'birth_date' => ['nullable', 'date_format:Y-m-d'],
                'code' => ['nullable', 'string', 'size:'.$codeLength],
                'document' => ['nullable', ...$photo],
                'pin' => ['nullable', 'digits:4', 'confirmed'],
            ],
            default => [],
        };
    }
}
