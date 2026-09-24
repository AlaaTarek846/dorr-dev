<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\WithdrawalMethodType;

/**
 * `data` differs per type, so its rules do too: a bank account needs an IBAN,
 * a mobile wallet a number — and either way the full details, since an update
 * replaces them (the app only ever sees the masked version).
 */
class WithdrawalMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::enum(WithdrawalMethodType::class)],
            'label' => ['nullable', 'string', 'max:60'],
            'is_favorite' => ['nullable', 'boolean'],
        ];

        return $rules + match ($this->input('type')) {
            WithdrawalMethodType::Bank->value => [
                'data.bank_name' => ['required', 'string', 'max:100'],
                'data.account_holder' => ['required', 'string', 'max:100'],
                'data.iban' => ['required', 'string', 'regex:/^[A-Za-z0-9]{15,34}$/'],
            ],
            WithdrawalMethodType::MobileWallet->value => [
                'data.provider_name' => ['required', 'string', 'max:100'],
                'data.number' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            ],
            default => ['data' => ['required', 'array']],
        };
    }

    /**
     * Only the fields of the chosen type — nothing extra reaches the encrypted column.
     *
     * @return array{type: string, label: string|null, is_favorite: bool, data: array<string, string>}
     */
    public function methodAttributes(): array
    {
        $validated = $this->validated();
        $keys = $validated['type'] === WithdrawalMethodType::Bank->value
            ? ['bank_name', 'account_holder', 'iban']
            : ['provider_name', 'number'];

        return [
            'type' => $validated['type'],
            'label' => $validated['label'] ?? null,
            'is_favorite' => (bool) ($validated['is_favorite'] ?? false),
            'data' => array_intersect_key($validated['data'], array_flip($keys)),
        ];
    }
}
