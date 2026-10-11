<?php

namespace Modules\Sports\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Services\SportsGovernor;

/** Sports switches: on, where, the live interval per tier, the reserve, the budget; each sport on/off and its share. */
class SportsSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sports-settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function show()
    {
        return ApiResponse::success($this->present(), __('api.retrieved'));
    }

    public function update(Request $request, SportsGovernor $governor)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'enabled_countries' => ['sometimes', 'nullable', 'array'],
            'enabled_countries.*' => ['integer', 'distinct', 'exists:countries,id'],
            'tier_seconds' => ['sometimes', 'array'],
            'tier_seconds.big' => ['sometimes', 'integer', 'min:30', 'max:1800'],
            'tier_seconds.normal' => ['sometimes', 'integer', 'min:30', 'max:1800'],
            'tier_seconds.minor' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'reserve_percent' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'daily_limit' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:1000000'],
            'predictions_enabled' => ['sometimes', 'boolean'],
            // Prizes stay closed in a country until it's opened here (legal review first).
            'prizes_countries' => ['sometimes', 'nullable', 'array'],
            'prizes_countries.*' => ['integer', 'distinct', 'exists:countries,id'],
            // Odds: information only, and only where the admin allows it (docs/sports-plan.md §10.7).
            'odds_countries' => ['sometimes', 'nullable', 'array'],
            'odds_countries.*' => ['integer', 'distinct', 'exists:countries,id'],
            'sports' => ['sometimes', 'array'],
            'sports.*.key' => ['required', 'string', 'exists:sports_sports,key'],
            'sports.*.status' => ['sometimes', 'boolean'],
            'sports.*.min_share_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
        $setting = SportsSetting::query()->firstOrCreate([]);
        if (array_key_exists('enabled_countries', $data)) {
            $data['enabled_countries'] = array_values(array_map('intval', $data['enabled_countries'] ?? [])) ?: null;
        }
        if (array_key_exists('odds_countries', $data)) {
            $data['odds_countries'] = array_values(array_map('intval', $data['odds_countries'] ?? [])) ?: null;
        }
        if (array_key_exists('prizes_countries', $data)) {
            $data['prizes_countries'] = array_values(array_map('intval', $data['prizes_countries'] ?? [])) ?: null;
        }
        if (isset($data['tier_seconds'])) {
            $data['tier_seconds'] = array_map('intval', $data['tier_seconds']) + (array) ($setting->tier_seconds ?? []);
        }
        $setting->update(collect($data)->except('sports')->all());
        foreach ($data['sports'] ?? [] as $row) {
            SportsSport::query()->where('key', $row['key'])->update(array_intersect_key($row, array_flip(['status', 'min_share_percent'])));
        }
        $governor->forget();

        return ApiResponse::success($this->present(), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $s = SportsSetting::current();

        return [
            'enabled' => (bool) $s->enabled,
            'enabled_countries' => array_values($s->enabled_countries ?? []),
            'tier_seconds' => ['big' => $s->secondsFor('big'), 'normal' => $s->secondsFor('normal'), 'minor' => $s->secondsFor('minor')],
            'reserve_percent' => (int) $s->reserve_percent,
            'daily_limit' => $s->daily_limit,
            'predictions_enabled' => (bool) ($s->predictions_enabled ?? true),
            'prizes_countries' => array_values($s->prizes_countries ?? []),
            'odds_countries' => array_values($s->odds_countries ?? []),
            'configured' => filled(config('services.api_sports.key')),
            'sports' => SportsSport::query()->orderBy('sort_order')->get()->map(fn (SportsSport $sp) => [
                'key' => $sp->key, 'name' => $sp->name(), 'emoji' => $sp->emoji(), 'status' => (bool) $sp->status, 'min_share_percent' => (int) $sp->min_share_percent,
                'competitions' => $sp->competitions()->where('tier', '!=', 'off')->count(),
            ])->values()->all(),
        ];
    }
}
