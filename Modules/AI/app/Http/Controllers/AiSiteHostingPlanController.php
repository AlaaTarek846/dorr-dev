<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\DB;
use Modules\AI\Http\Requests\AiSiteHostingPlanRequest;
use Modules\AI\Http\Requests\AiSiteOfferPricesRequest;
use Modules\AI\Http\Resources\AiSiteHostingPlanResource;
use Modules\AI\Models\AiSiteHostingPlan;

/** Admin: hosting plans (monthly/yearly) and their price per country. */
class AiSiteHostingPlanController extends Controller
{
    public function index()
    {
        return ApiResponse::success(AiSiteHostingPlanResource::collection(
            AiSiteHostingPlan::query()->withCount('prices')->orderBy('sort_order')->orderBy('id')->get()
        ));
    }

    public function store(AiSiteHostingPlanRequest $request)
    {
        $plan = AiSiteHostingPlan::query()->create($request->validated() + ['sort_order' => 0]);

        return ApiResponse::success(new AiSiteHostingPlanResource($plan), __('ai.site_offer_saved'), 201);
    }

    public function show(AiSiteHostingPlan $plan)
    {
        return ApiResponse::success(new AiSiteHostingPlanResource($plan));
    }

    public function update(AiSiteHostingPlanRequest $request, AiSiteHostingPlan $plan)
    {
        $plan->update($request->validated());

        return ApiResponse::success(new AiSiteHostingPlanResource($plan->fresh()), __('ai.site_offer_saved'));
    }

    public function destroy(AiSiteHostingPlan $plan)
    {
        if ($plan->hostings()->exists()) {
            $plan->update(['is_active' => false]);

            return ApiResponse::success(new AiSiteHostingPlanResource($plan->fresh()), __('ai.site_offer_deactivated'));
        }

        $plan->prices()->delete();
        $plan->delete();

        return ApiResponse::success(null, __('ai.site_offer_deleted'));
    }

    public function prices(AiSiteHostingPlan $plan)
    {
        $existing = $plan->prices()->get()->keyBy('country_id');

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

        return ApiResponse::success(['plan' => new AiSiteHostingPlanResource($plan), 'rows' => $rows]);
    }

    /** Replaces the whole price list: countries not sent stop selling this plan. */
    public function syncPrices(AiSiteOfferPricesRequest $request, AiSiteHostingPlan $plan)
    {
        $rows = collect($request->validated('prices'));
        $countries = Country::query()->whereIn('id', $rows->pluck('country_id'))->get()->keyBy('id');

        DB::transaction(function () use ($plan, $rows, $countries) {
            $plan->prices()->whereNotIn('country_id', $rows->pluck('country_id'))->delete();

            foreach ($rows as $row) {
                $country = $countries->get((int) $row['country_id']);

                if ($country?->currency_id === null) {
                    continue;
                }

                $plan->prices()->updateOrCreate(
                    ['country_id' => $country->id],
                    ['currency_id' => $country->currency_id, 'price' => $row['price']],
                );
            }
        });

        return ApiResponse::success(null, __('ai.site_offer_saved'));
    }
}
