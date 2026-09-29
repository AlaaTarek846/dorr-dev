<?php

namespace Modules\Wallet\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `percent` moves real money in both directions (a fee taken, or a bonus the
 * platform pays for), so two extra guards beyond type checks: a non-zero
 * percent must be typed twice (`percent_confirmation`), and a bonus (negative)
 * must carry a `max_amount_minor` cap so one typo can't give away a wallet.
 */
class WalletFeeRuleRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('wallet_fee_rules'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'owner_type' => ['nullable', Rule::in(['user', 'provider'])],
            'percent' => ['required', 'numeric', 'between:-100,100'],
            'percent_confirmation' => ['required_unless:percent,0', 'nullable', 'same:percent'],
            'min_amount_minor' => ['nullable', 'integer', 'min:0'],
            'max_amount_minor' => [
                Rule::requiredIf(fn () => (float) $this->input('percent') < 0),
                'nullable', 'integer', 'min:1',
                Rule::when($this->filled('min_amount_minor'), ['gte:min_amount_minor']),
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses_per_owner' => ['nullable', 'integer', 'min:1'],
            'budget_total_minor' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
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
            'translations.*.name' => ['required', 'string', 'min:2', 'max:100'],
            'translations.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
