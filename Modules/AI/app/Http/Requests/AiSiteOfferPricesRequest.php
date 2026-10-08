<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;

class AiSiteOfferPricesRequest extends FormRequest
{
    use TranslatesSiteAttributes;

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
            'prices' => ['present', 'array', 'max:300'],
            'prices.*.country_id' => ['required', 'integer', 'distinct', 'exists:countries,id'],
            'prices.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
