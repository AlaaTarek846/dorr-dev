<?php

namespace Modules\WatchEarn\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\WatchEarn\Models\WeSetting;

/** WE-040 — Watch & Earn per country: the price of a view and its split, the player, the limits and the waits. */
class WatchEarnSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        // The countries list is also what the finance page filters by: either page's permission reads it.
        return [
            new Middleware('permission:watch-earn-settings.view|watch-earn-finance.view,'.AdminPermissionMiddleware::GUARD, only: ['index']),
            ...AdminPermissionMiddleware::fromActionMethodMap('watch-earn-settings', [['update', ['update']]]),
        ];
    }

    /** GET watch-earn/settings — every active country with its row (or the defaults). */
    public function index()
    {
        $countries = Country::query()->where('status', true)->with(['translations', 'currency'])->orderBy('id')->get();
        $rows = WeSetting::query()->get()->keyBy('country_id');

        return ApiResponse::success($countries->map(fn (Country $c) => $this->present($rows[$c->id] ?? WeSetting::forCountry($c->id), $c))->values()->all(), __('api.retrieved'));
    }

    /** PUT watch-earn/settings/{country} */
    public function update(Request $request, Country $country)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'price_per_view_minor' => ['required', 'integer', 'min:0'],
            'platform_share_minor' => ['required', 'integer', 'min:0'],
            'view_reward_minor' => ['required', 'integer', 'min:0'],
            'interaction_reward_minor' => ['required', 'integer', 'min:0'],
            'unclaimed_interaction' => ['required', Rule::in([WeSetting::UNCLAIMED_PLATFORM, WeSetting::UNCLAIMED_NOT_CHARGED])],
            'viewing_mode' => ['required', Rule::in(['auto', 'manual'])],
            'image_seconds' => ['required', 'integer', 'min:3', 'max:120'],
            'video_max_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            'video_max_mb' => ['required', 'integer', 'min:1', 'max:500'],
            'min_ad_wallet_minor' => ['required', 'integer', 'min:0'],
            'low_balance_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'report_threshold' => ['required', 'integer', 'min:0', 'max:10000'],
            'interaction_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'view_reward_wait_hours' => ['required', 'integer', 'min:0', 'max:8760'],
            'interaction_reward_wait_hours' => ['required', 'integer', 'min:0', 'max:8760'],
        ]);

        // The split must add up to the price, or the money wouldn't balance.
        if ($data['price_per_view_minor'] !== $data['platform_share_minor'] + $data['view_reward_minor'] + $data['interaction_reward_minor']) {
            throw ValidationException::withMessages(['price_per_view_minor' => __('watch_earn.errors.split_mismatch')]);
        }
        if ($data['enabled'] && $data['price_per_view_minor'] <= 0) {
            throw ValidationException::withMessages(['price_per_view_minor' => __('watch_earn.errors.price_required')]);
        }

        $row = WeSetting::query()->updateOrCreate(['country_id' => $country->id], $data);

        return ApiResponse::success($this->present($row, $country->load(['translations', 'currency'])), __('api.updated'));
    }

    private function present(WeSetting $s, Country $c): array
    {
        return [
            'country' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName()],
            'currency' => ['code' => $c->currency?->code, 'decimals' => (int) ($c->currency?->decimal_places ?? 2)],
        ] + collect(WeSetting::defaults())->keys()->mapWithKeys(fn ($k) => [$k => $s->{$k}])->all();
    }
}
