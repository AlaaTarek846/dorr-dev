<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiGatewayRequest extends FormRequest
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
            'environment' => ['nullable', 'string', 'in:production,staging,development'],
            'default_policy_id' => ['nullable', 'integer', 'exists:ai_routing_policies,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
