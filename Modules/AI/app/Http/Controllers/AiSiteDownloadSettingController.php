<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Resources\AiSiteOfferResource;
use Modules\AI\Models\AiSiteOffer;

/**
 * Admin: the one switch behind "download the site as a ZIP" - on/off, free or paid. The price per
 * country is managed with the usual offer price endpoints, using the offer id returned here.
 */
class AiSiteDownloadSettingController extends Controller
{
    public function show()
    {
        return ApiResponse::success(new AiSiteOfferResource($this->offer()->loadCount('prices')));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'is_free' => ['required', 'boolean'],
        ]);

        $offer = $this->offer();
        $offer->update($data);

        return ApiResponse::success(new AiSiteOfferResource($offer->fresh()->loadCount('prices')), __('ai.site_offer_saved'));
    }

    /** Created on first look so the admin always has something to switch; free and on = how it worked before. */
    protected function offer(): AiSiteOffer
    {
        return AiSiteOffer::query()->firstOrCreate(
            ['kind' => AiSiteOffer::KIND_SOURCE_DOWNLOAD],
            [
                'name' => 'Source download',
                'code' => 'source_download',
                'generations_included' => 0,
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 0,
            ],
        );
    }
}
