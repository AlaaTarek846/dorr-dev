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
        ]);

        if ($this->input('service_id') === '' || $this->input('service_id') === 'null') {
            $this->merge(['service_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->translationRules()),
            'ordered' => ['service_id' => ['nullable', 'integer', 'exists:service_categories,id']],
            'reorder' => $this->reorderRules(),
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reorderRules(): array
    {
        return [
            'service_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'integer', 'distinct', 'exists:faqs,id'],
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
            'ordered_ids' => __('validation.attributes.sort_order'),
            'ordered_ids.*' => __('validation.attributes.sort_order'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.question' => __('validation.attributes.translations.*.question'),
            'translations.*.answer' => __('validation.attributes.translations.*.answer'),
        ];
    }
}
