<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSiteOffer;

/**
 * One website-builder offer / edit pack. name / description are in the request's language (X-Locale / locale
 * middleware) - the same keys the apps have always read (AiSiteOfferDto on Android/iOS), so they need no change.
 * `is_active` is kept next to the catalog's `status`. The customer gets its country's price; only the admin panel
 * gets `translations[]` (every language, for the edit form's language tabs), `prices_count` and the dates.
 *
 * @mixin AiSiteOffer
 */
class AiSiteOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/admin/*', 'admin/*');
        $price = $admin ? null : $this->priceFor(currentCountry());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'kind' => $this->kind,
            'description' => $this->description,
            'generations_included' => $this->generations_included,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'is_free' => $this->is_free,
            'sort_order' => $this->sort_order,
            $this->mergeWhen(! $admin, [
                'price' => $price['price'] ?? null,
                'currency' => $price['currency'] ?? null,
                'available' => $price !== null,
            ]),
            $this->mergeWhen($admin, fn () => [
                'prices_count' => $this->prices_count ?? $this->prices()->count(),
                'translations' => $this->relationLoaded('translations')
                    ? $this->translations->map(fn ($item) => [
                        'locale' => $item->locale,
                        'name' => $item->name,
                        'description' => $item->description,
                    ])->values()
                    : [],
                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
                'deleted_at' => $this->deleted_at?->toISOString(),
            ]),
        ];
    }
}
