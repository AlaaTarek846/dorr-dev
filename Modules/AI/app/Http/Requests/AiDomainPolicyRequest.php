<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiDomainPolicy;

/**
 * Admin AI domain policy form - same shape as the catalog requests (CountryRequest, AiIntentRequest). Name and
 * description are translated (translations[]); the domain key, switches, prompt addition and disclaimer are not.
 */
class AiDomainPolicyRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        // Older callers send is_active; the catalog column is status.
        if (! $this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->input('is_active')]);
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->policyTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_domain_policies'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';

        return [
            // One policy per domain (the classifier looks it up by key) - unique, the trash included.
            'domain_key' => [
                $isUpdate ? 'sometimes' : 'required',
                Rule::in(AiDomainPolicy::DOMAINS),
                Rule::unique('ai_domain_policies', 'domain_key')->ignore($this->route('policy')),
            ],
            'risk_level' => ['nullable', Rule::in(AiDomainPolicy::RISKS)],
            'requires_jurisdiction' => ['nullable', 'boolean'],
            'requires_triage' => ['nullable', 'boolean'],
            'sandbox_required' => ['nullable', 'boolean'],
            'allowlist_enforced' => ['nullable', 'boolean'],
            'system_prompt_addition' => ['nullable', 'string', 'max:2000'],
            'disclaimer_text' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One entry per stored language: name required, description optional.
     *
     * @return array<string, mixed>
     */
    protected function policyTranslationRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:150'],
            'translations.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.name'),
            'translations.*.description' => __('validation.attributes.description'),
        ];
    }
}
