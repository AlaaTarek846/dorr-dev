<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (owner) and review (admin) a withdrawal request. The review rules
 * are where "receipt mandatory on approve" and "reason mandatory on reject"
 * are enforced (docs/wallet-structure.md §7).
 */
class WithdrawalRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store' => [
                'withdrawal_method_id' => ['required', 'integer'],
                'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            ],
            'approve' => [
                'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
                'note' => ['nullable', 'string', 'max:500'],
            ],
            'reject' => [
                'rejection_reason' => ['required', 'string', 'min:3', 'max:500'],
            ],
            default => [],
        };
    }
}
