<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionRequest extends FormRequest
{
    private const GUARD = 'admin_api';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $permissionId = $this->route('permission');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->writeRules($permissionId),
            'update' => $this->writeRules($permissionId),
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('permissions', 'id')->where(fn ($q) => $q->where('guard_name', self::GUARD)),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function writeRules(mixed $permissionId): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'name')
                    ->where(fn ($q) => $q->where('guard_name', self::GUARD))
                    ->ignore($permissionId),
            ],
            'group_name' => ['nullable', 'string', 'max:255'],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('validation.attributes.permission_name'),
            'group_name' => __('validation.attributes.group_name'),
            'service_category_id' => __('validation.attributes.service_category_id'),
        ];
    }
}
