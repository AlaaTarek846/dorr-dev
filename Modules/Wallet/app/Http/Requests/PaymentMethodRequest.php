<?php

namespace Modules\Wallet\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\PaymentMethodType;

class PaymentMethodRequest extends FormRequest
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
        $id = $this->route('payment_method');

        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules($id), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('payment_methods'),
            'syncCountries' => $this->syncCountriesRules(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $id): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('payment_methods', 'code')->ignore($id)],
            'gateway' => ['required', 'string', Rule::in(array_filter(['myfatoorah', 'arb', 'urpay', 'manual', config('wallet.sandbox_enabled') ? 'sandbox' : null]))],
            'type' => ['required', Rule::enum(PaymentMethodType::class)],
            'is_global' => ['nullable', 'boolean'],
            'supports_topup' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            // A new online method needs its gateway credentials (except the fake sandbox bank, which has
            // none). When *editing*, leaving them out keeps the stored ones — the API never returns them,
            // so forcing the admin to retype every secret just to rename a method would be a trap.
            'credentials' => [
                'nullable', 'array',
                Rule::requiredIf(fn () => $this->route()->getActionMethod() === 'store'
                    && $this->input('type') === PaymentMethodType::Online->value
                    && $this->input('gateway') !== 'sandbox'),
            ],
            'media.logo' => ['nullable', 'image', 'max:2048'],
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

    /**
     * @return array<string, mixed>
     */
    protected function syncCountriesRules(): array
    {
        return [
            'countries' => ['present', 'array'],
            'countries.*.country_id' => ['required', 'integer', 'distinct', 'exists:countries,id'],
            'countries.*.min_amount_minor' => ['nullable', 'integer', 'min:0'],
            'countries.*.max_amount_minor' => ['nullable', 'integer', 'min:0', 'gte:countries.*.min_amount_minor'],
            'countries.*.status' => ['nullable', 'boolean'],
        ];
    }
}
