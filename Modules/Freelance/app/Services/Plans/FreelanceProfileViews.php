<?php

namespace Modules\Freelance\Services\Plans;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Visits to a freelancer's profile and ready-made services (FL-L11). Only a number per day, kind and place is kept — never who looked —
 * and a client who opens the same thing again within a few minutes counts once (`dedupe_minutes`), so refreshing a page does not
 * inflate it. Recording never fails the page being read: a counter that cannot be written is skipped, not an error.
 */
class FreelanceProfileViews
{
    public const PROFILE = 'profile';

    public const SERVICE = 'service';

    /** FS-704: one portfolio work opened (`service_id` holds the work's id); the totals of the profile and the services leave it out. */
    public const WORK = 'work';

    /** Where the visit came from: an offer on the client's project, the portfolio, or the ready-made services market. */
    public const SOURCES = ['offer', 'portfolio', 'market'];

    /** @param  int|string  $viewer  who looked (a client id), only to avoid counting the same look twice — never stored */
    public function record(int $freelancerId, string $kind, string $source, int|string $viewer, int $serviceId = 0): void
    {
        $minutes = (int) config('freelance.profile_views.dedupe_minutes', 10);
        if ($minutes > 0 && ! Cache::add("freelance:view:{$freelancerId}:{$kind}:{$serviceId}:{$source}:{$viewer}", 1, now()->addMinutes($minutes))) {
            return;
        }

        try {
            $now = now();
            DB::table('freelance_profile_views')->upsert(
                [['freelancer_id' => $freelancerId, 'service_id' => $serviceId, 'kind' => $kind, 'source' => $source, 'day' => $now->toDateString(), 'views' => 1, 'created_at' => $now, 'updated_at' => $now]],
                ['freelancer_id', 'service_id', 'kind', 'source', 'day'],
                ['views' => DB::raw('views + 1'), 'updated_at' => $now],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The visits since a day and in the stretch of the same length before it, so the screen can say «up 20%».
     *
     * @return array{profile: int, service: int, profile_before: int, service_before: int, by_source: array<string, int>}
     */
    public function totals(int $freelancerId, Carbon $since, int $days): array
    {
        $before = $since->copy()->subDays($days);
        $rows = DB::table('freelance_profile_views')->where('freelancer_id', $freelancerId)->whereIn('kind', [self::PROFILE, self::SERVICE])->where('day', '>=', $before->toDateString())
            ->selectRaw('kind, source, day >= ? as current_period, sum(views) as n', [$since->toDateString()])->groupBy('kind', 'source', 'current_period')->get();

        $out = ['profile' => 0, 'service' => 0, 'profile_before' => 0, 'service_before' => 0, 'by_source' => array_fill_keys(self::SOURCES, 0)];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            if ((int) $r->current_period === 1) {
                $out[$r->kind] += $n;
                $out['by_source'][$r->source] = ($out['by_source'][$r->source] ?? 0) + $n;
            } else {
                $out[$r->kind.'_before'] += $n;
            }
        }

        return $out;
    }

    /**
     * The visits over time, in the same buckets as the earnings (a week for 30 days, a calendar month for 90 and 365).
     *
     * @param  list<string>  $starts  the first day of each bucket, oldest first
     * @return list<array{from: string, profile: int, service: int}>
     */
    public function series(int $freelancerId, array $starts, bool $weekly): array
    {
        if ($starts === []) {
            return [];
        }
        $points = array_fill_keys($starts, ['profile' => 0, 'service' => 0]);
        $first = Carbon::parse($starts[0])->startOfDay();
        $today = now()->startOfDay();

        DB::table('freelance_profile_views')->where('freelancer_id', $freelancerId)->whereIn('kind', [self::PROFILE, self::SERVICE])->where('day', '>=', $first->toDateString())->get(['kind', 'day', 'views'])
            ->each(function ($r) use (&$points, $weekly, $today) {
                $day = Carbon::parse($r->day)->startOfDay();
                $key = $weekly
                    ? $today->copy()->subDays(7 * (intdiv(abs((int) $day->diffInDays($today)), 7) + 1) - 1)->toDateString()
                    : $day->copy()->startOfMonth()->toDateString();
                if (isset($points[$key])) {
                    $points[$key][$r->kind] += (int) $r->views;
                }
            });

        return collect($points)->map(fn (array $p, string $from) => ['from' => $from] + $p)->values()->all();
    }

    /**
     * The ready-made services looked at the most in the period, with the orders they brought (the visits only count in that period).
     *
     * @return list<array{id: int, title: string, views: int, orders: int}>
     */
    public function topServices(int $freelancerId, Carbon $since, int $limit): array
    {
        $views = DB::table('freelance_profile_views')->where('freelancer_id', $freelancerId)->where('kind', self::SERVICE)->where('day', '>=', $since->toDateString())
            ->selectRaw('service_id, sum(views) as n')->groupBy('service_id')->orderByDesc('n')->limit($limit)->pluck('n', 'service_id');
        if ($views->isEmpty()) {
            return [];
        }
        $titles = DB::table('freelance_services')->whereIn('id', $views->keys())->pluck('title', 'id');
        $orders = DB::table('freelance_service_orders')->whereIn('service_id', $views->keys())->where('created_at', '>=', $since)->selectRaw('service_id, count(*) as n')->groupBy('service_id')->pluck('n', 'service_id');

        return $views->map(fn ($n, $id) => ['id' => (int) $id, 'title' => (string) ($titles[$id] ?? ''), 'views' => (int) $n, 'orders' => (int) ($orders[$id] ?? 0)])->values()->all();
    }

    /**
     * FS-704: how often the works were opened in the period and in the stretch before it, and per work (the ones looked at, most first).
     *
     * @return array{total: int, before: int, by_work: array<int, int>, by_work_before: array<int, int>}
     */
    public function workTotals(int $freelancerId, Carbon $since, int $days): array
    {
        $before = $since->copy()->subDays($days);
        $rows = DB::table('freelance_profile_views')->where('freelancer_id', $freelancerId)->where('kind', self::WORK)->where('day', '>=', $before->toDateString())
            ->selectRaw('service_id, day >= ? as current_period, sum(views) as n', [$since->toDateString()])->groupBy('service_id', 'current_period')->get();
        $out = ['total' => 0, 'before' => 0, 'by_work' => [], 'by_work_before' => []];
        foreach ($rows as $r) {
            if ((int) $r->current_period === 1) {
                $out['total'] += (int) $r->n;
                $out['by_work'][(int) $r->service_id] = (int) $r->n;
            } else {
                $out['before'] += (int) $r->n;
                $out['by_work_before'][(int) $r->service_id] = (int) $r->n;
            }
        }
        arsort($out['by_work']);

        return $out;
    }
}
