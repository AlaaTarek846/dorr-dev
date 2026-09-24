<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSafetyRuleRequest extends FormRequest
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
            'safety_policy_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_safety_policies,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'condition' => ['nullable', 'array'],
            'action' => ['nullable', 'string', 'in:allow,block,review,require_confirmation,sanitize'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
