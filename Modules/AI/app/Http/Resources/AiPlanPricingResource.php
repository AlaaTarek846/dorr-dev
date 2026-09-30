<?php

namespace Modules\AI\Http\Resources;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Customer-facing plan listing (AiUserSubscriptionController::plans()) -
 * same shape as AiPlanResource (the admin one) but with price/original_price
 * /currency/discount_percent resolved for the requesting customer's own
 * country via AiPlan::resolvedPriceFor(), instead of always the plan's
 * global base price. A plain JsonResource can't take extra constructor
 * arguments through the usual ::collection() helper, so collectionFor()
 * below is what the controller calls instead.
 */
class AiPlanPricingResource extends AiPlanResource
{
    public function __construct($resource, protected ?Country $country = null)
    {
        parent::__construct($resource);
    }

    /**
     * @param  iterable  $plans
     * @return list<array<string, mixed>>
     */
    public static function collectionFor(iterable $plans, ?Country $country): array
    {
        return Collection::make($plans)
            ->map(fn ($plan) => (new static($plan, $country))->toArray(request()))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolved = $this->resource->resolvedPriceFor($this->country);

        return array_merge(parent::toArray($request), [
            'price' => $resolved['price'],
            'original_price' => $resolved['original_price'],
            'discount_percent' => $this->discountPercentFor($resolved),
            'currency' => $resolved['currency'],
            'is_country_specific_price' => $resolved['is_country_specific'],
        ]);
    }

    /**
     * @param  array{price: float, original_price: ?float, currency: string, is_country_specific: bool}  $resolved
     */
    protected function discountPercentFor(array $resolved): ?int
    {
        if ($resolved['original_price'] === null || $resolved['original_price'] <= $resolved['price']) {
            return null;
        }

        return (int) round((1 - ($resolved['price'] / $resolved['original_price'])) * 100);
    }
}
