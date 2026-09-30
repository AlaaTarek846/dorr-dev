<?php

namespace App\Http\Requests\General;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Http\Requests\Concerns\SanitizesRichText;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;

class FaqRequest extends FormRequest
{
    use HasCatalogRules;
    use SanitizesRichText;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeRichTranslations(['answer']);

        $this->merge([
            'status' => filter_var($this->input('status', true), FILTER_VALIDATE_BOOLEAN),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('faqs'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'service_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function translationRules(): array
    {
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => ['required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.question' => ['required', 'string', 'min:2', 'max:255'],
            'translations.*.answer' => ['required', 'string', 'min:2', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'service_id' => __('validation.attributes.service_id'),
            'status' => __('validation.attributes.status'),
            'sort_order' => __('validation.attributes.sort_order'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.question' => __('validation.attributes.translations.*.question'),
            'translations.*.answer' => __('validation.attributes.translations.*.answer'),
        ];
    }
}
