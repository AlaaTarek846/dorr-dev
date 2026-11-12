<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;
use Modules\AI\Models\AiSiteHostingPlan;

/** Admin site hosting plan form - same shape as the catalog requests (CountryRequest, AiPlanRequest). */
class AiSiteHostingPlanRequest extends FormRequest
{
    use HasCatalogRules;
    use TranslatesSiteAttributes {
        attributes as siteAttributes;
    }

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

        if ($this->has('status')) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        // A cleared order means "first", never NULL (the column is NOT NULL).
        if ($this->has('sort_order') && ($this->input('sort_order') === null || $this->input('sort_order') === '')) {
            $this->merge(['sort_order' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->planTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_site_hosting_plans'),
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
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:60',
                'alpha_dash',
                Rule::unique('ai_site_hosting_plans', 'code')->ignore($this->route('plan')),
            ],
            'period' => [$isUpdate ? 'sometimes' : 'required', Rule::in([AiSiteHostingPlan::PERIOD_MONTHLY, AiSiteHostingPlan::PERIOD_YEARLY])],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * One entry per stored language: name required, description optional.
     *
     * @return array<string, mixed>
     */
    protected function planTranslationRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:150'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge($this->siteAttributes(), [
            'status' => __('validation.ai_site_attributes.is_active'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.name'),
            'translations.*.description' => __('validation.attributes.description'),
        ]);
    }
}
