<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `credentials` is deliberately never read here, not even to redact it
 * selectively — the safest way to guarantee a secret never leaks in a
 * response is for the code that builds the response to never touch it.
 *
 * Builds its own translation fields (name + description) instead of
 * App\Http\Resources\Concerns\FormatsTranslations, which only carries `name`.
 */
class PaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'gateway' => $this->gateway,
            'type' => $this->type?->value,
            'is_global' => (bool) $this->is_global,
            'supports_topup' => (bool) $this->supports_topup,
            'status' => (bool) $this->status,
            // Listed but not chargeable yet (no gateway credentials entered): the app shows "coming soon".
            'coming_soon' => ! $this->resource->isConfigured(),
            'sort_order' => $this->sort_order,
            'logo_url' => $this->getSingleMedia('logo')?->getUrl(),
            'countries' => $this->whenLoaded('countryLinks', fn () => $this->countryLinks->map(fn ($link) => [
                'country_id' => $link->country_id,
                'country_code' => $link->country?->code,
                'min_amount_minor' => $link->min_amount_minor,
                'max_amount_minor' => $link->max_amount_minor,
                'status' => (bool) $link->status,
            ])),
            'name' => $this->resource->translatedName(),
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($item) => [
                'locale' => $item->locale,
                'name' => $item->name,
                'description' => $item->description,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
