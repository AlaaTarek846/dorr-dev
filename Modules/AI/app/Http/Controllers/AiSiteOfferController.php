<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\DB;
use Modules\AI\Http\Requests\AiSiteOfferPricesRequest;
use Modules\AI\Http\Requests\AiSiteOfferRequest;
use Modules\AI\Http\Resources\AiSiteOfferResource;
use Modules\AI\Models\AiSiteOffer;

/** Admin: the standalone "build me a site" products and their per-country prices. */
class AiSiteOfferController extends Controller
{
    public function index()
    {
        return ApiResponse::success(AiSiteOfferResource::collection(
            AiSiteOffer::query()->withCount('prices')->orderBy('sort_order')->orderBy('id')->get()
        ));
    }

    public function store(AiSiteOfferRequest $request)
    {
        $offer = AiSiteOffer::query()->create($request->validated() + ['sort_order' => 0]);

        return ApiResponse::success(new AiSiteOfferResource($offer), __('ai.site_offer_saved'), 201);
    }

    public function show(AiSiteOffer $offer)
    {
        return ApiResponse::success(new AiSiteOfferResource($offer));
    }

    public function update(AiSiteOfferRequest $request, AiSiteOffer $offer)
    {
        $offer->update($request->validated());

        return ApiResponse::success(new AiSiteOfferResource($offer->fresh()), __('ai.site_offer_saved'));
    }

    public function destroy(AiSiteOffer $offer)
    {
        // Purchases keep pointing at the offer for their history, so an offer that was ever sold is only switched off.
        if ($offer->purchases()->exists()) {
            $offer->update(['is_active' => false]);

            return ApiResponse::success(new AiSiteOfferResource($offer->fresh()), __('ai.site_offer_deactivated'));
        }

        $offer->prices()->delete();
        $offer->delete();

        return ApiResponse::success(null, __('ai.site_offer_deleted'));
    }

    /** One row per active country; `price` null = not sold there. */
    public function prices(AiSiteOffer $offer)
    {
        $existing = $offer->prices()->get()->keyBy('country_id');

        $rows = Country::query()->where('status', true)->with(['translation', 'currency'])->get()
            ->sortBy(fn (Country $c) => $c->translatedName() ?? $c->code)
            ->values()
            ->map(fn (Country $c) => [
                'country_id' => $c->id,
                'country_code' => $c->code,
                'country_name' => $c->translatedName() ?? $c->code,
                'currency_code' => $c->currency?->code,
                'currency_symbol' => $c->currency?->symbol,
                'price' => $existing->get($c->id)?->price,
            ]);

        return ApiResponse::success(['offer' => new AiSiteOfferResource($offer), 'rows' => $rows]);
    }

    /** Replaces the whole price list: countries not sent stop selling this offer. */
    public function syncPrices(AiSiteOfferPricesRequest $request, AiSiteOffer $offer)
    {
        $rows = collect($request->validated('prices'));
        $countries = Country::query()->whereIn('id', $rows->pluck('country_id'))->get()->keyBy('id');

        DB::transaction(function () use ($offer, $rows, $countries) {
            $offer->prices()->whereNotIn('country_id', $rows->pluck('country_id'))->delete();

            foreach ($rows as $row) {
                $country = $countries->get((int) $row['country_id']);

                if ($country?->currency_id === null) {
                    continue;
                }

                $offer->prices()->updateOrCreate(
                    ['country_id' => $country->id],
                    ['currency_id' => $country->currency_id, 'price' => $row['price']],
                );
            }
        });

        return ApiResponse::success(null, __('ai.site_offer_saved'));
    }
}
