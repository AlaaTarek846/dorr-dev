<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiRoutingPolicyRequest extends FormRequest
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
            'scope_type' => ['nullable', 'string', 'in:global,country,service'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'service_key' => ['nullable', 'string', 'max:100'],
            'plan_id' => ['nullable', 'integer', 'exists:ai_plans,id'],
            'selection_strategy' => ['nullable', 'string', 'in:priority,round_robin,cost_optimized,latency_optimized'],
            'fallback_enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
