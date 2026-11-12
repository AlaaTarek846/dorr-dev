<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiFeatureFlag;

/**
 * Admin AI feature flag form - same shape as the catalog requests (CountryRequest, AiIntentRequest). The only text
 * people read is the description (translations[]); everything else is technical.
 */
class AiFeatureFlagRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        // Older callers send is_enabled (the flag's old column) or is_active; the catalog column is status.
        foreach (['is_enabled', 'is_active'] as $legacy) {
            if (! $this->has('status') && $this->has($legacy)) {
                $this->merge(['status' => $this->input($legacy)]);
            }
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        foreach (['key', 'model_key', 'tool_key', 'domain', 'environment'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) === '' ? null : trim($this->input($field))]);
            }
        }

        // The gate compares the country code as stored - keep it the way the apps send it (upper case).
        if (is_string($this->input('country_code'))) {
            $code = strtoupper(trim($this->input('country_code')));
            $this->merge(['country_code' => $code === '' ? null : $code]);
        }

        // The column is NOT NULL (default production): a cleared environment means production.
        if ($this->has('environment') && $this->input('environment') === null) {
            $this->merge(['environment' => 'production']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->flagTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_feature_flags'),
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
            'key' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'target_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(AiFeatureFlag::TARGETS)],
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'model_key' => ['nullable', 'string', 'max:150'],
            'tool_key' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'domain' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'boolean'],
            // Older callers send the description flat: it is written into every stored language.
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * One entry per stored language when sent; the description is optional.
     *
     * @return array<string, mixed>
     */
    protected function flagTranslationRules(): array
    {
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => ['sometimes', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
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
            'translations.*.description' => __('validation.attributes.description'),
        ];
    }
}
