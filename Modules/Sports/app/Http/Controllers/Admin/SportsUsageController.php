<?php

namespace Modules\Sports\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Services\SportsGovernor;
use Modules\Sports\Support\ApiSportsClient;
use Throwable;

/** The budget screen: today's requests, what's planned, the stretch factor, live now; and a manual sync. */
class SportsUsageController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sports-usage', [
            ['view', ['show']],
            ['update', ['sync']],
        ]);
    }

    public function show(ApiSportsClient $client, SportsGovernor $governor)
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->toDateString();
        $plan = $governor->plan($now);
        $factors = $governor->factors($now);
        $sports = SportsSport::query()->orderBy('sort_order')->get();

        return ApiResponse::success([
            'configured' => $client->configured(),
            'limit' => $client->dailyLimit(),
            'used' => $client->usedToday(),
            'left' => $client->leftToday(),
            'reserve' => $governor->reserve(),
            'planned_rest_of_day' => (int) round(array_sum($plan)),
            'by_sport' => $sports->map(fn (SportsSport $s) => [
                'key' => $s->key, 'name' => $s->name(), 'emoji' => $s->emoji(), 'status' => (bool) $s->status,
                'used' => $client->usedToday($s->key),
                'planned' => (int) round($plan[$s->key] ?? 0),
                'factor' => round($factors[$s->key] ?? 1, 2),
                'intervals' => $s->status ? collect(['big', 'normal', 'minor'])->mapWithKeys(fn ($t) => [$t => $governor->interval($s, $t, $now)])->all() : null,
                'live_now' => SportsMatch::query()->where('sport_id', $s->id)->whereIn('status', SportsMatch::LIVE)->count(),
                'today' => SportsMatch::query()->where('sport_id', $s->id)->whereBetween('starts_at', [$now->startOfDay(), $now->endOfDay()])->count(),
            ])->values()->all(),
            'by_endpoint' => DB::table('sports_api_usage')->where('date', $today)->orderByDesc('requests')->get(['sport_key', 'endpoint', 'requests']),
            'last_days' => DB::table('sports_api_usage')->where('date', '>=', $now->subDays(13)->toDateString())
                ->select('date', DB::raw('sum(requests) as requests'))->groupBy('date')->orderBy('date')->get(),
        ], __('api.retrieved'));
    }

    /** POST sync {what: schedule | standings | tick} — run it now (counts against the budget). */
    public function sync(Request $request, SportsEngine $engine)
    {
        $data = $request->validate(['what' => ['required', Rule::in(['schedule', 'standings', 'tick'])]]);
        try {
            $result = match ($data['what']) {
                'schedule' => $engine->syncDueSchedules(),
                'standings' => $engine->syncDueStandings(),
                'tick' => $engine->tick(),
            };
        } catch (Throwable $e) {
            throw new SportsException('provider', 502, [], ['detail' => $e->getMessage()]);
        }

        return ApiResponse::success($result, __('api.updated'));
    }
}
