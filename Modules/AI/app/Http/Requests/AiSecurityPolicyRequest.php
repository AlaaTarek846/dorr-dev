<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSecurityPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'authentication_required' => ['nullable', 'boolean'],
            'authorization_required' => ['nullable', 'boolean'],
            'tenant_isolation_required' => ['nullable', 'boolean'],
            'rate_limit_enabled' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
