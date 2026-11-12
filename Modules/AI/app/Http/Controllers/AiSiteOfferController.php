<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\AI\Http\Requests\AiSiteOfferPricesRequest;
use Modules\AI\Http\Requests\AiSiteOfferRequest;
use Modules\AI\Http\Resources\AiSiteOfferResource;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Services\AiSiteOfferService;
use Modules\AI\Support\AiAdminScreens;

/**
 * Admin website-builder offers and edit packs - the catalog CRUD (like CountryController): index/show, store, update,
 * status, bulk delete, trash/restore/force delete, each behind its ai-site-offers.* permission - plus the price per
 * country (reading = view, saving = update). `?kind=site|edit_pack` picks the screen.
 */
class AiSiteOfferController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'ai-site-offers';
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    protected static function extraAdminPermissionActionMethods(): array
    {
        return [
            ['update', ['syncPrices']],
        ];
    }

    /**
     * The catalog permissions, plus: reading the prices = ai-site-offers.view OR ai-site-download.view (the
     * site-download screen shows the download offer's prices - AiAdminScreens::READERS). Saving them stays
     * ai-site-offers.update.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        $readers = array_map(
            fn (string $group) => $group.'.view',
            ['ai-site-offers', ...(AiAdminScreens::READERS['ai-site-offers'] ?? [])],
        );

        return [
            ...parent::middleware(),
            new Middleware('permission:'.implode('|', $readers).','.AdminPermissionMiddleware::GUARD, only: ['prices']),
        ];
    }

    public function __construct(AiSiteOfferService $service)
    {
        parent::__construct($service);
    }

    public function store(AiSiteOfferRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(AiSiteOfferRequest $request, int|string $offer)
    {
        return $this->service->updateRecord($offer, $request->validated());
    }

    public function deleteMultiple(AiSiteOfferRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(AiSiteOfferRequest $request, int|string $offer)
    {
        return $this->service->changeStatus($offer, (bool) $request->validated('status'));
    }

    /**
     * One row per active country; `price` null = not sold there. The source-download screen uses it too (its offer
     * id comes from admin/v1/ai-site-download), so this binds the plain model, not this screen's list.
     */
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

        return ApiResponse::success(['offer' => new AiSiteOfferResource($offer->loadCount('prices')), 'rows' => $rows]);
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
