<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\ValidatesCountryPhone;

/** Same country-aware phone rules as the rider, captain and freelancer apps. */
class ProviderOtpRequest extends FormRequest
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
}
