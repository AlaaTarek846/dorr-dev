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
        $merge = [
            'phone' => $this->input('phone') ?: null,
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
        ];

        if ($this->has('service_category_ids') && ! is_array($this->input('service_category_ids'))) {
            $merge['service_category_ids'] = [];
        }

        if ($this->has('status')) {
            $merge['status'] = filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
            'status' => ['nullable', 'boolean'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'admin_api'),
            ],
            'service_category_ids' => ['required', 'array', 'min:1'],
            'service_category_ids.*' => ['integer', 'distinct', 'exists:service_categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role_id' => __('validation.attributes.role_id'),
            'service_category_ids' => __('validation.attributes.service_category_ids'),
        ];
    }
}
