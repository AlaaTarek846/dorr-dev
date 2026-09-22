<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
        $roleId = $this->route('role');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->writeRules($roleId),
            'update' => $this->writeRules($roleId),
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('roles', 'id')->where(fn ($q) => $q->where('guard_name', self::GUARD)),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function writeRules(mixed $roleId): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:200',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q->where('guard_name', self::GUARD))
                    ->ignore($roleId),
            ],
            'permission_names' => ['nullable', 'array'],
            'permission_names.*' => [
                'string',
                'distinct',
                Rule::exists('permissions', 'name')->where(fn ($q) => $q->where('guard_name', self::GUARD)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('validation.attributes.role_name'),
            'permission_names' => __('validation.attributes.permission_names'),
        ];
    }
}
