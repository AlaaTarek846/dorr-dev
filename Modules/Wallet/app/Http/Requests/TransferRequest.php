<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\WalletBucket;

/**
 * Two steps, two rule sets: `lookup` finds and shows the recipient (by phone or
 * by wallet number); `store` moves the money and only accepts the token the
 * lookup produced. The phone format itself is validated per country inside
 * TransferRecipientResolver (it needs the request's country).
 */
class TransferRequest extends FormRequest
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
        if ($this->route()->getActionMethod() === 'lookup') {
            return [
                'mode' => ['required', Rule::in(['phone', 'wallet', 'qr'])],
                'phone' => ['required_if:mode,phone', 'nullable', 'string', 'max:30'],
                'wallet_number' => ['required_if:mode,wallet', 'nullable', 'string', 'max:30'],
                'qr' => ['required_if:mode,qr', 'nullable', 'string', 'max:200'],
            ];
        }

        return [
            'recipient_token' => ['required', 'string', 'max:2000'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            // Optional: which of the sender's own buckets to pay from (default: bonus money first).
            'from_bucket' => ['nullable', Rule::enum(WalletBucket::class)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
