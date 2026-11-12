<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Services\AiPiiSanitizer;

/**
 * Admin AI provider data rule form - catalog rules per action (no translations: a rule has no text to translate).
 * One rule per provider: a provider whose rule is in the trash can't get a second one (restore it instead) - the
 * database keeps provider_id unique.
 */
class AiProviderDataRuleRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => $this->baseRules(),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_provider_data_rules'),
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
            'provider_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'integer',
                'exists:ai_providers,id',
                Rule::unique('ai_provider_data_rules', 'provider_id')->ignore($isUpdate ? $this->route('rule') : null),
            ],
            'sanitize_pii' => ['nullable', 'boolean'],
            'sanitize_secrets' => ['nullable', 'boolean'],
            // Read by AiPiiSanitizer::applyTransformationRules: mask_fields (which kinds to mask) and redact_terms
            // (literal words). Other keys are kept but ignored.
            'transformation_rules' => ['nullable', 'array'],
            'transformation_rules.mask_fields' => ['sometimes', 'array'],
            'transformation_rules.mask_fields.*' => ['string', Rule::in([...AiPiiSanitizer::MASK_FIELDS, 'secret'])],
            'transformation_rules.redact_terms' => ['sometimes', 'array', 'max:100'],
            'transformation_rules.redact_terms.*' => ['string', 'min:2', 'max:100'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_id.unique' => __('ai.provider_data_rule_exists'),
        ];
    }
}
