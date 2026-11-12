<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;

/** Admin AI routing policy form - same shape as the catalog requests (CountryRequest, AiPlanRequest). */
class AiRoutingPolicyRequest extends FormRequest
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

        // A cleared priority means 0, never NULL (the column is NOT NULL).
        if ($this->has('priority') && ($this->input('priority') === null || $this->input('priority') === '')) {
            $this->merge(['priority' => 0]);
        }

        foreach (['country_code', 'service_key'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper(trim($this->input('country_code')))]);
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
            'deleteMultiple' => $this->deleteMultipleRules('ai_routing_policies'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'scope_type' => ['nullable', 'string', 'in:global,country,service'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'service_key' => ['nullable', 'string', 'max:100'],
            'plan_id' => ['nullable', 'integer', 'exists:ai_plans,id'],
            // cost_optimized was dropped: no model or provider has a price to compare (AiRoutingEngine::STRATEGIES).
            'selection_strategy' => ['nullable', 'string', 'in:priority,round_robin,latency_optimized'],
            'fallback_enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One name per stored language.
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
        ];
    }
}
