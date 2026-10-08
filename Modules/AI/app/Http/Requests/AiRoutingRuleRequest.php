<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiRoutingRuleRequest extends FormRequest
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
            'routing_policy_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_routing_policies,id'],
            'intent_id' => ['nullable', 'integer', 'exists:ai_intents,id'],
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'model_key' => ['nullable', 'string', 'max:150'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'selection_config' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
