<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;

/**
 * Bucket and reason are mandatory on purpose — there is no default bucket and
 * no anonymous correction (docs/wallet-structure.md §2).
 */
class WalletAdjustmentRequest extends FormRequest
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
        return [
            'direction' => ['required', Rule::enum(WalletDirection::class)],
            'bucket' => ['required', Rule::enum(WalletBucket::class)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}
