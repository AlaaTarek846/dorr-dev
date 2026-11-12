<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiSafetyRule;

/**
 * Admin AI safety rule form - same shape as the catalog requests (CountryRequest, AiIntentRequest). The name is
 * translated (translations[]); the condition, the action and the priority are not.
 */
class AiSafetyRuleRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->ruleTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_safety_rules'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $current = $isUpdate ? AiSafetyRule::withTrashed()->find($this->route('rule'))?->safety_policy_id : null;

        return [
            // A policy in the trash can't be picked - unless the rule already belongs to it (saving it again keeps it).
            'safety_policy_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'integer',
                Rule::exists('ai_safety_policies', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->when($current, fn ($q) => $q->orWhere('id', $current))),
            ],
            'condition' => ['nullable', 'array'],
            'action' => ['nullable', 'string', Rule::in(AiSafetyRule::ACTIONS)],
            'priority' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One entry per stored language: the name is required.
     *
     * @return array<string, mixed>
     */
    protected function ruleTranslationRules(): array
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
