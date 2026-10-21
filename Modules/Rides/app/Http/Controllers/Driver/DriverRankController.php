<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverPerformanceNote;
use Modules\Rides\Models\DriverRank;
use Modules\Rides\Services\Drivers\DriverRanks;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * The captain's rank and performance (D-5, D-6, D-239..D-259): the probation, the rank and the way to the next
 * one (what's missing, from the server's thresholds), the numbers, a pending fall and its date, every rank side
 * by side, and the record of notes / warnings with their appeals.
 */
class DriverRankController extends Controller
{
    public function __construct(private readonly DriverRanks $ranks, private readonly RidePolicies $policies) {}

    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $state = $this->ranks->evaluate($driver);
        $metrics = (array) $state->metrics;
        $all = DriverRank::query()->where('status', true)->orderBy('sort_order')->get();
        $current = $state->rank;
        $next = $current === null ? null : $all->first(fn (DriverRank $r) => ! $r->probation && $r->sort_order > $current->sort_order);
        $policy = $this->policies->for((int) ($driver->country_id ?? 0) ?: (int) \App\Models\Country::query()->value('id'));

        return ApiResponse::success([
            'probation' => $this->ranks->probation($driver),
            'rank' => $current === null ? null : self::rank($current),
            'next' => $next === null ? null : self::rank($next) + ['missing' => $next->missing($metrics)],
            'metrics' => $metrics,
            'falling' => $state->downgrade_due_at === null ? null : [
                'to' => $state->fallingTo === null ? null : self::rank($state->fallingTo),
                'due_at' => $state->downgrade_due_at->toIso8601String(),
                'missing' => $current?->missing($metrics) ?? [],
            ],
            'ranks' => $all->map(fn (DriverRank $r) => self::rank($r) + ['current' => $r->id === $current?->id])->values(),
            'appeal_allowed' => $policy->bool('ranks.appeal_allowed'),
            'appeal_window_days' => $policy->int('ranks.appeal_window_days') ?: 14,
            'notes' => DriverPerformanceNote::query()->where('driver_id', $driver->id)->orderByDesc('id')->limit(50)->get()->map(fn ($n) => self::note($n))->values(),
        ], __('api.retrieved'));
    }

    public function appeal(Request $request, int $note): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:5', 'max:1000']]);
        $row = DriverPerformanceNote::query()->whereKey($note)->first() ?? throw new RidesException('performance_note_not_found', 404);

        return ApiResponse::success(self::note($this->ranks->appeal($this->driver($request), $row, $data['text'])), __('rides.ranks.appealed'));
    }

    /** @return array<string, mixed> */
    public static function rank(DriverRank $r): array
    {
        return [
            'id' => $r->id, 'slug' => $r->slug, 'name' => $r->localized('name'), 'color' => $r->color, 'probation' => $r->probation,
            'benefits' => $r->benefitList(),
            'thresholds' => collect(DriverRank::RULES)->mapWithKeys(fn ($rule) => [$rule[1] => (int) $r->{$rule[0]}])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function note(DriverPerformanceNote $n): array
    {
        return [
            'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body,
            'appeal_status' => $n->appeal_status, 'appeal_text' => $n->appeal_text, 'appeal_answer' => $n->appeal_answer,
            'created_at' => $n->created_at?->toIso8601String(), 'answered_at' => $n->answered_at?->toIso8601String(),
        ];
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
