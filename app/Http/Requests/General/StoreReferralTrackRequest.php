<?php

namespace App\Http\Requests\General;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferralTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('referral_code')) {
            $this->merge([
                'referral_code' => strtoupper(trim((string) $this->input('referral_code'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prefix = preg_quote((string) config('referral.prefix', 'DORRFC-'), '/');
        $length = (int) config('referral.suffix_length', 6);

        return [
            'referral_code' => ['required', 'string', 'regex:/^'.$prefix.'[A-Z0-9]{'.$length.'}$/'],
        ];
    }
}
