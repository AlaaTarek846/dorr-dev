<?php

namespace Modules\Admin\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->input('phone') ?: null,
            'phone_code' => $this->input('phone_code') ?: null,
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adminId = $this->route('admin');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->storeRules($adminId),
            'update' => $this->updateRules($adminId),
            'changeStatus' => [
                'status' => ['required', 'boolean'],
            ],
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:admins,id'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeRules(mixed $adminId): array
    {
        return array_merge($this->baseRules($adminId), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateRules(mixed $adminId): array
    {
        return array_merge($this->baseRules($adminId), [
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $adminId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($adminId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'boolean'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }
}
