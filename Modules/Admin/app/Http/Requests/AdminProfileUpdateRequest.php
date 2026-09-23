<?php

namespace Modules\Admin\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class AdminProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->input('phone') ?: null,
            'phone_code' => $this->input('phone_code') ?: null,
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
        $adminId = $this->user('admin_api')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'email' => [
                'required',
                'email',
                'min:2',
                'max:50',
                Rule::unique('admins', 'email')->ignore($adminId),
            ],
            'phone' => ['required', 'string', 'max:50'],
            'phone_code' => ['nullable', 'string', 'max:10'],
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
            'phone' => __('validation.attributes.phone'),
            'phone_code' => __('validation.attributes.phone_code'),
            'gender' => __('validation.attributes.gender'),
            'country_id' => __('validation.attributes.country_id'),
            'avatar' => __('validation.attributes.avatar'),
        ];
    }
}
