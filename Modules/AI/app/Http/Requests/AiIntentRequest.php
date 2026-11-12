<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin AI intent form - same shape as the catalog requests (CountryRequest, AiPlanRequest). */
class AiIntentRequest extends FormRequest
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

        if (is_string($this->input('key'))) {
            $this->merge(['key' => trim($this->input('key'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->intentTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_intents'),
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
            // The technical code the classifier looks up (AiIntentClassifier) - unique, the trash included.
            'key' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('ai_intents', 'key')->ignore($this->route('intent')),
            ],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One entry per stored language: name required, description optional.
     *
     * @return array<string, mixed>
     */
    protected function intentTranslationRules(): array
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
