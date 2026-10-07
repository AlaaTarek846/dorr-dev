<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Modules\AI\Http\Requests\AiPlanPriceRequest;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiPlanPrice;

/**
 * Admin management of a single plan's per-country price overrides - a
 * dedicated surface from the plan create/update modal on purpose (the
 * business asked for a separate screen/tab), reached via
 * admin/v1/ai-plans/{plan}/prices.
 */
class AiPlanPriceController extends Controller
{
    /**
     * One row per ACTIVE country (not just the ones already customized),
     * merged with any existing override - exactly what the admin table
     * needs to render in one request: every country either shows its
     * custom price or a clearly-marked fallback to the plan's base price.
     */
    public function index(AiPlan $plan)
    {
        $overrides = $plan->prices()->with('currency')->get()->keyBy('country_id');

        $countries = Country::query()
            ->where('status', true)
            ->with(['translation', 'currency'])
            ->get()
            ->sortBy(fn (Country $country) => $country->translatedName() ?? $country->code)
            ->values();

        $rows = $countries->map(function (Country $country) use ($overrides) {
            /** @var AiPlanPrice|null $override */
            $override = $overrides->get($country->id);

            return [
                'ai_plan_price_id' => $override?->id,
                'country_id' => $country->id,
                'country_code' => $country->code,
                'country_name' => $country->translatedName() ?? $country->code,
                'country_currency_code' => $country->currency?->code,
                'country_currency_symbol' => $country->currency?->symbol,
                'price' => $override?->price,
                'original_price' => $override?->original_price,
                'is_country_specific' => $override !== null,
            ];
        })->values();

        return ApiResponse::success([
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'currency' => $plan->currency,
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Upsert-by-country: one row per (plan, country) is the whole model, so
     * "set the price for Saudi Arabia" is always the same call whether a
     * row already exists or not.
     */
    public function store(AiPlanPriceRequest $request, AiPlan $plan)
    {
        $country = Country::query()->findOrFail($request->integer('country_id'));

        $price = AiPlanPrice::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'country_id' => $country->id],
            [
                'currency_id' => $country->currency_id,
                'price' => $request->input('price'),
                'original_price' => $request->input('original_price'),
            ],
        );

        return ApiResponse::success([
            'ai_plan_price_id' => $price->id,
            'country_id' => $country->id,
            'price' => $price->price,
            'original_price' => $price->original_price,
            'is_country_specific' => true,
        ], __('ai.plan_price_saved'));
    }

    public function destroy(AiPlan $plan, AiPlanPrice $price)
    {
        abort_unless((int) $price->plan_id === (int) $plan->id, 404);

        $price->delete();

        return ApiResponse::success(null, __('ai.plan_price_deleted'));
    }
}
