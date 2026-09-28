<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\User\Models\User;

class ChangePhoneRequest extends FormRequest
{
    use ValidatesCountryPhone;

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
            'dial_code' => ['required', 'string', 'max:5'],
            'phone' => ['required', 'string', 'max:15'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateCountryPhone($validator, (string) $this->input('dial_code'), (string) $this->input('phone'));

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fullPhone = '+'.ltrim((string) $this->input('dial_code'), '+').$this->input('phone');

            if ($fullPhone === $this->user('user_api')?->phone) {
                $validator->errors()->add('phone', __('api.phone_same_as_current'));

                return;
            }

            if (User::query()->where('phone', $fullPhone)->where('id', '!=', $this->user('user_api')?->id)->exists()) {
                $validator->errors()->add('phone', __('validation.unique', ['attribute' => __('validation.attributes.phone')]));
            }
        });
    }
}
