<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiLanguageEvaluation;

/**
 * Admin AI language evaluation form - same shape as the catalog requests (CountryRequest, AiSafetyPolicyRequest).
 * The name is translated (translations[]); the notes are free-text admin notes and the rest are numbers / codes.
 * `evaluation_status` is the result (pending / running / passed / failed); `status` is the on/off switch.
 */
class AiLanguageEvaluationRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->input('is_active')]);
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        foreach (['variant_id', 'pass_rate', 'evaluated_at', 'notes'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        // A cleared count means 0, never NULL (the column is NOT NULL).
        if ($this->has('test_case_count') && ($this->input('test_case_count') === null || $this->input('test_case_count') === '')) {
            $this->merge(['test_case_count' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->evaluationTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_language_evaluations'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $currentVariant = $isUpdate ? AiLanguageEvaluation::withTrashed()->whereKey($this->route('evaluation'))->value('variant_id') : null;

        return [
            'language_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:languages,id'],
            // A variant in the trash can't be picked - unless the evaluation already points at it.
            'variant_id' => ['nullable', 'integer', Rule::exists('ai_language_variants', 'id')->where(
                fn ($q) => $q->whereNull('deleted_at')->when($currentVariant, fn ($q) => $q->orWhere('id', $currentVariant)),
            )],
            'test_case_count' => ['nullable', 'integer', 'min:0'],
            'pass_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evaluation_status' => ['nullable', Rule::in(AiLanguageEvaluation::EVALUATION_STATUSES)],
            'evaluated_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One entry per stored language: the name.
     *
     * @return array<string, mixed>
     */
    protected function evaluationTranslationRules(): array
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
