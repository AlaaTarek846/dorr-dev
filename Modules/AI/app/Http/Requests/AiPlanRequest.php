<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin AI plan form - same shape as the catalog requests (CountryRequest, FaqRequest). */
class AiPlanRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    /** A cleared video/site field means "none", never NULL (the columns are NOT NULL). */
    protected function prepareForValidation(): void
    {
        foreach (['video_daily_limit', 'video_max_seconds', 'site_projects_limit', 'site_daily_generations'] as $field) {
            if ($this->has($field) && ($this->input($field) === null || $this->input($field) === '')) {
                $this->merge([$field => 0]);
            }
        }

        if ($this->has('status')) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        // Empty feature lines are dropped, so an untouched extra row never blocks saving.
        if (is_array($this->input('translations'))) {
            $this->merge(['translations' => array_map(function ($translation) {
                if (is_array($translation) && array_key_exists('features', $translation)) {
                    $translation['features'] = array_values(array_filter(
                        array_map(fn ($line) => is_string($line) ? trim($line) : $line, (array) $translation['features']),
                        fn ($line) => $line !== '' && $line !== null,
                    ));
                }

                return $translation;
            }, $this->input('translations'))]);
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
            'deleteMultiple' => $this->deleteMultipleRules('ai_plans'),
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
                'max:100',
                Rule::unique('ai_plans', 'code')->ignore($this->route('plan')),
            ],
            'usage_minutes' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:0'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0'],
            // NULL = unlimited images a day, 0 = images not in the plan.
            'image_daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // 0 = video generation not in the plan.
            'video_daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'video_max_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'site_projects_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'site_daily_generations' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'is_trial' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * One entry per stored language: name required, the rest optional.
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
            'translations.*.description' => ['nullable', 'string', 'max:1000'],
            'translations.*.badge' => ['nullable', 'string', 'max:60'],
            'translations.*.features' => ['nullable', 'array', 'max:12'],
            'translations.*.features.*' => ['string', 'max:150'],
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
            'translations.*.features' => __('validation.attributes.features'),
            'translations.*.features.*' => __('validation.attributes.features'),
        ];
    }
}
