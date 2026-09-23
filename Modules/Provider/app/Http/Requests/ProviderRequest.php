<?php

namespace Modules\Provider\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Enums\UserStatus;

class ProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'phone' => $this->input('phone') ?: null,
            'phone_code' => $this->input('phone_code') ?: null,
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
        ];

        if ($this->has('service_category_ids') && ! is_array($this->input('service_category_ids'))) {
            $merge['service_category_ids'] = [];
        }

        if ($this->has('remove_avatar')) {
            $merge['remove_avatar'] = filter_var($this->input('remove_avatar'), FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $providerId = $this->route('provider');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->storeRules($providerId),
            'update' => $this->updateRules($providerId),
            'changeStatus' => [
                'status' => ['required', new Enum(UserStatus::class)],
            ],
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:providers,id'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeRules(mixed $providerId): array
    {
        return array_merge($this->baseRules($providerId), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateRules(mixed $providerId): array
    {
        return array_merge($this->baseRules($providerId), [
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $providerId): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'email' => [
                'required',
                'email',
                'min:2',
                'max:50',
                Rule::unique('providers', 'email')->ignore($providerId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'gender' => ['required', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'service_category_ids' => ['nullable', 'array'],
            'service_category_ids.*' => ['integer', 'distinct', 'exists:service_categories,id'],
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
            'status' => __('validation.attributes.status'),
            'password' => __('validation.attributes.password'),
            'password_confirmation' => __('validation.attributes.password_confirmation'),
            'avatar' => __('validation.attributes.avatar'),
            'service_category_ids' => __('validation.attributes.service_category_ids'),
        ];
    }
}
