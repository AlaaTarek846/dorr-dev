<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "I want my number to be this one" — same country-aware phone validation as login (dial_code + phone,
 * checked against the country's own format), so a phone-change can never end in an unreachable number.
 */
class PhoneChangeRequest extends FormRequest
{
    use ValidatesCountryPhone;

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
            'dial_code' => ['required', 'string', 'max:5'],
            'phone' => ['required', 'string', 'max:15'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateCountryPhone($validator, (string) $this->input('dial_code'), (string) $this->input('phone'));
        });
    }

    public function fullPhone(): string
    {
        return '+'.ltrim((string) $this->validated('dial_code'), '+').$this->validated('phone');
    }
}
