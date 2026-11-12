<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiLanguageVariant;

/**
 * Admin AI language variant form - same shape as the catalog requests (CountryRequest, AiSafetyPolicyRequest). The
 * name is translated (translations[]); the language, code and style are not.
 */
class AiLanguageVariantRequest extends FormRequest
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

        foreach (['status', 'is_default'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->variantTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_language_variants'),
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
            'language_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:languages,id'],
            // Unique within its language, the trash included (the DB index ai_language_variants_language_code_unique covers
            // trashed rows too - like CountryRequest's code): a 422 instead of a 500 from the database.
            'code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:50', $this->uniqueCodeRule()],
            'style' => ['nullable', Rule::in([AiLanguageVariant::STYLE_FORMAL, AiLanguageVariant::STYLE_CONVERSATIONAL])],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The (language_id, code) pair is unique; on update the language is the sent one or the stored one.
     */
    protected function uniqueCodeRule(): \Illuminate\Validation\Rules\Unique
    {
        $id = $this->route('variant');
        $languageId = $this->input('language_id');

        if ($languageId === null && $id !== null) {
            $languageId = AiLanguageVariant::withTrashed()->whereKey($id)->value('language_id');
        }

        return Rule::unique('ai_language_variants', 'code')
            ->where(fn ($query) => $query->where('language_id', (int) $languageId))
            ->ignore($id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => __('ai.language_variant_code_taken'),
        ];
    }

    /**
     * One entry per stored language: the name.
     *
     * @return array<string, mixed>
     */
    protected function variantTranslationRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:100'],
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
