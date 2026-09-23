<?php

namespace Modules\User\Http\Requests;

use App\Models\Country;
use Illuminate\Validation\Validator;

/**
 * Shared country-aware phone validation for the mobile OTP flow.
 * Uses the country's `phone_starts_with` and `phone_length` columns.
 */
trait ValidatesCountryPhone
{
    private function validateCountryPhone(Validator $validator, string $dialCode, string $phone): void
    {
        $country = Country::query()
            ->whereRaw("REPLACE(dial_code, '+', '') = ?", [ltrim($dialCode, '+')])
            ->where('status', true)
            ->first();

        if (! $country) {
            $validator->errors()->add('dial_code', __('api.phone_invalid_country'));

            return;
        }

        if ($country->phone_length !== null && mb_strlen($phone) !== (int) $country->phone_length) {
            $validator->errors()->add('phone', __('api.phone_invalid_length', ['length' => $country->phone_length]));
        }

        $startsWith = $country->phone_starts_with;

        if ($startsWith !== null && $startsWith !== '' && ! str_starts_with($phone, $startsWith)) {
            $validator->errors()->add('phone', __('api.phone_invalid_start', ['starts_with' => $startsWith]));
        }
    }
}
