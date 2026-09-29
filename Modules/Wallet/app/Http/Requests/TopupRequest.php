<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Idempotency-Key header is the client's retry token — validated as an
     * ordinary field so a missing one is a normal 422, not a 500.
     */
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
        $amount = ['required', 'integer', 'min:1', 'max:100000000000'];
        $method = ['required', 'integer', 'exists:payment_methods,id'];

        return match ($this->route()->getActionMethod()) {
            'quote' => ['payment_method_id' => $method, 'amount_minor' => $amount],
            'store' => [
                'payment_method_id' => $method,
                'amount_minor' => $amount,
                'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            ],
            'confirm' => ['otp' => ['required', 'string', 'max:20']],
            default => [],
        };
    }
}
