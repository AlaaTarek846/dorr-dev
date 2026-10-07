<?php

namespace App\Http\Requests\General;

use App\Enums\LegalPageType;
use App\Http\Requests\Concerns\HasCatalogRules;
use App\Http\Requests\Concerns\SanitizesRichText;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LegalPageRequest extends FormRequest
{
    use HasCatalogRules;
    use SanitizesRichText;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeRichTranslations(['content']);

        $serviceId = $this->input('service_id');
        $isBlank = $serviceId === null || $serviceId === '' || $serviceId === 0 || $serviceId === '0';

        $this->merge([
            'type' => $this->input('type', LegalPageType::Privacy->value),
            'service_id' => $isBlank ? null : (int) $serviceId,
            'status' => filter_var($this->input('status', true), FILTER_VALIDATE_BOOLEAN),
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
            'deleteMultiple' => $this->deleteMultipleRules('legal_pages'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $id = $this->route('legal_page');

        return [
            'type' => ['required', 'string', Rule::in(LegalPageType::values())],
            'service_id' => [
                'nullable',
                'integer',
                'exists:service_categories,id',
                // One live page per (type, service): privacy+1 blocks privacy+1 but not term+1.
                Rule::unique('legal_pages', 'service_id')
                    ->ignore($id)
                    ->whereNull('deleted_at')
                    ->where('type', $this->input('type'))
                    ->whereNotNull('service_id'),
            ],
            'status' => ['nullable', 'boolean'],
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
            'translations.*.content' => ['required', 'string', 'min:2', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('validation.attributes.legal_page_type'),
            'service_id' => __('validation.attributes.service_id'),
            'status' => __('validation.attributes.status'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.content' => __('validation.attributes.translations.*.content'),
        ];
    }
}