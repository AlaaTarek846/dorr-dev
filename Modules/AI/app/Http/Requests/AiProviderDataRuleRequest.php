<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiProviderDataRuleRequest extends FormRequest
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
        $ruleId = $this->route('providerDataRule');

        return [
            'provider_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'integer',
                'exists:ai_providers,id',
                Rule::unique('ai_provider_data_rules', 'provider_id')->ignore($ruleId),
            ],
            'sanitize_pii' => ['nullable', 'boolean'],
            'sanitize_secrets' => ['nullable', 'boolean'],
            'transformation_rules' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
