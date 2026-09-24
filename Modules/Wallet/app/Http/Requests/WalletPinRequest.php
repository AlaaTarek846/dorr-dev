<?php

namespace Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletPinRequest extends FormRequest
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
        $newPin = ['required', 'digits:4', 'confirmed'];

        return match ($this->route()->getActionMethod()) {
            'store' => ['pin' => $newPin],
            'update' => ['current_pin' => ['required', 'digits:4'], 'pin' => $newPin],
            default => [],
        };
    }
}
