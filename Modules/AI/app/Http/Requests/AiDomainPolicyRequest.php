<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiDomainPolicy;

class AiDomainPolicyRequest extends FormRequest
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
            'domain_key' => [
                $isUpdate ? 'sometimes' : 'required',
                Rule::in([
                    AiDomainPolicy::DOMAIN_LEGAL,
                    AiDomainPolicy::DOMAIN_HEALTH,
                    AiDomainPolicy::DOMAIN_EDUCATION,
                    AiDomainPolicy::DOMAIN_CODE,
                    AiDomainPolicy::DOMAIN_MARKETING,
                    AiDomainPolicy::DOMAIN_GENERAL_INFO,
                ]),
            ],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'risk_level' => ['nullable', Rule::in([
                AiDomainPolicy::RISK_LOW,
                AiDomainPolicy::RISK_MEDIUM,
                AiDomainPolicy::RISK_HIGH,
                AiDomainPolicy::RISK_CRITICAL,
            ])],
            'requires_jurisdiction' => ['nullable', 'boolean'],
            'requires_triage' => ['nullable', 'boolean'],
            'sandbox_required' => ['nullable', 'boolean'],
            'allowlist_enforced' => ['nullable', 'boolean'],
            'system_prompt_addition' => ['nullable', 'string', 'max:2000'],
            'disclaimer_text' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
