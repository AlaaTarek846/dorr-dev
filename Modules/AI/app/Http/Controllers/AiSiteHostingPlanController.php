<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\DB;
use Modules\AI\Http\Requests\AiSiteHostingPlanRequest;
use Modules\AI\Http\Requests\AiSiteOfferPricesRequest;
use Modules\AI\Http\Resources\AiSiteHostingPlanResource;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Services\AiSiteHostingPlanService;

/**
 * Admin site hosting plans (monthly / yearly) - the catalog CRUD (like CountryController): index/show, store, update,
 * status, bulk delete, trash/restore/force delete, each behind its ai-site-hosting-plans.* permission - plus the price
 * per country (reading = view, saving = update).
 */
class AiSiteHostingPlanController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-site-hosting-plans';
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    protected static function extraAdminPermissionActionMethods(): array
    {
        return [
            ['view', ['prices']],
            ['update', ['syncPrices']],
        ];
    }

    public function __construct(AiSiteHostingPlanService $service)
    {
        parent::__construct($service);
    }

    public function store(AiSiteHostingPlanRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiSiteHostingPlanRequest $request, int|string $plan)
    {
        return $this->service->updateRecord($plan, $request->validated());
    }

    public function deleteMultiple(AiSiteHostingPlanRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiSiteHostingPlanRequest $request, int|string $plan)
    {
        return $this->service->changeStatus($plan, (bool) $request->validated('status'));
    }

    /** One row per active country; `price` null = not sold there. */
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

        return ApiResponse::success(['plan' => new AiSiteHostingPlanResource($plan->loadCount('prices')), 'rows' => $rows]);
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
