<?php

namespace Modules\User\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
            'remove_avatar' => filter_var($this->input('remove_avatar'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user('user_api')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'email' => [
                'required',
                'email',
                'min:2',
                'max:50',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Deliberately not here: the phone number is the wallet's login identity and its
            // transfer address, so changing it goes through PhoneChangeService instead (its own
            // OTP-to-the-new-number + wallet-PIN + uniqueness checks) — not a silent profile field.
            'gender' => ['required', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('validation.attributes.name'),
            'email' => __('validation.attributes.email'),
            'gender' => __('validation.attributes.gender'),
            'country_id' => __('validation.attributes.country_id'),
            'avatar' => __('validation.attributes.avatar'),
        ];
    }
}
