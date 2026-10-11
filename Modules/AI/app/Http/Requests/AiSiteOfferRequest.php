<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;

class AiSiteOfferRequest extends FormRequest
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
        $offer = $this->route('offer');
        $id = is_object($offer) ? $offer->id : $offer;

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('ai_site_offers', 'code')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'generations_included' => ['required', 'integer', 'min:1', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
