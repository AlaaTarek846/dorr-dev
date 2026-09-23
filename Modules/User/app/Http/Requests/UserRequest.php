<?php

namespace Modules\User\Http\Requests;

use App\Enums\Gender;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->input('phone') ?: null,
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->storeRules($userId),
            'update' => $this->updateRules($userId),
            'changeStatus' => [
                'status' => ['required', new Enum(UserStatus::class)],
            ],
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeRules(mixed $userId): array
    {
        return array_merge($this->baseRules($userId), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateRules(mixed $userId): array
    {
        return array_merge($this->baseRules($userId), [
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $userId): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'email' => [
                'required',
                'email',
                'min:2',
                'max:50',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['required', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
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
            'gender' => __('validation.attributes.gender'),
            'country_id' => __('validation.attributes.country_id'),
            'status' => __('validation.attributes.status'),
            'password' => __('validation.attributes.password'),
            'password_confirmation' => __('validation.attributes.password_confirmation'),
        ];
    }
}
