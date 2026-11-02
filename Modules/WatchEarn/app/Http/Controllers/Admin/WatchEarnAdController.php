<?php

namespace Modules\WatchEarn\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Http\Resources\AdResource;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdReport;
use Modules\WatchEarn\Models\Reward;
use Modules\WatchEarn\Services\AdService;
use Modules\WatchEarn\Services\ExportService;
use Modules\WatchEarn\Services\StatsService;

/**
 * WE-041..043 — the review queue, every ad with its stats and reports, suspending / restoring, and the money report.
 */
class WatchEarnAdController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            ...AdminPermissionMiddleware::fromActionMethodMap('watch-earn-ads', [
                ['view', ['index', 'counts', 'show', 'reports']],
                ['update', ['approve', 'reject', 'suspend', 'unsuspend']],
            ]),
            ...AdminPermissionMiddleware::fromActionMethodMap('watch-earn-finance', [
                ['view', ['finance', 'financeExport']],
            ]),
        ];
    }

    public function __construct(
        private readonly AdService $ads,
        private readonly StatsService $stats,
    ) {}

    /** GET watch-earn/ads?status=&country_id=&search= */
    public function index(Request $request)
    {
        $request->attributes->set('we_admin', true);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn (AdStatus $s) => $s->value, AdStatus::cases()))],
            'country_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'reported' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $page = Ad::query()
            ->when($data['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($data['country_id'] ?? null, fn (Builder $q, $c) => $q->where('country_id', $c))
            ->when(! empty($data['reported']), fn (Builder $q) => $q->where('reports_count', '>', 0))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('uuid', $search)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->with(['user', 'cities', 'country.currency'])
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::fromPaginator($page, AdResource::collection($page->items())->resolve($request), __('api.retrieved'));
    }

    /** GET watch-earn/ads/counts — the tiles above the table. */
    public function counts()
    {
        $byStatus = Ad::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success([
            'by_status' => $byStatus->all(),
            'reported' => Ad::query()->where('reports_count', '>', 0)->whereNotIn('status', ['rejected', 'stopped', 'ended'])->count(),
        ], __('api.retrieved'));
    }

    /** GET watch-earn/ads/{ad} — the modal: everything about it, its stats and the latest reports. */
    public function show(Request $request, Ad $ad)
    {
        $request->attributes->set('we_admin', true);
        $ad->load(['user', 'cities', 'country.currency']);

        return ApiResponse::success([
            'ad' => (new AdResource($ad))->resolve($request),
            'stats' => $this->stats->forAd($ad),
            'reports' => $this->reportRows($ad, 10),
        ], __('api.retrieved'));
    }

    /** GET watch-earn/ads/{ad}/reports */
    public function reports(Ad $ad)
    {
        return ApiResponse::success($this->reportRows($ad, 200), __('api.retrieved'));
    }

    public function approve(Request $request, Ad $ad)
    {
        return $this->respond($request, $this->ads->approve($ad, (int) $request->user()->id));
    }

    public function reject(Request $request, Ad $ad)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($request, $this->ads->reject($ad, (int) $request->user()->id, $data['reason']));
    }

    public function suspend(Request $request, Ad $ad)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->respond($request, $this->ads->suspend($ad, $data['reason'] ?? null));
    }

    public function unsuspend(Request $request, Ad $ad)
    {
        return $this->respond($request, $this->ads->unsuspend($ad));
    }

    /** GET watch-earn/finance?country_id=&from=&to= — the platform's share, rewards, and what ad wallets hold. */
    public function finance(Request $request)
    {
        [$country, $from, $to] = $this->period($request);

        return ApiResponse::success($this->financeReport($country, $from, $to), __('api.retrieved'));
    }

    /** GET watch-earn/finance/export?format=pdf|xlsx&country_id=&from=&to= — the same numbers as a file. */
    public function financeExport(Request $request, ExportService $exports)
    {
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]])['format'];
        [$country, $from, $to] = $this->period($request);
        [$bytes, $name, $mime] = $exports->finance(Country::query()->findOrFail($country), $from, $to, $this->financeReport($country, $from, $to), $format);

        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** @return array{0: int, 1: Carbon, 2: Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            (int) $data['country_id'],
            isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay(),
            isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay(),
        ];
    }

    /** @return array<string, mixed> */
    private function financeReport(int $country, Carbon $from, Carbon $to): array
    {
        $revenue = (int) DB::table('financial_entries')
            ->join('financial_categories', 'financial_categories.id', '=', 'financial_entries.category_id')
            ->where('financial_categories.slug', 'watch_earn_revenue')->where('financial_entries.country_id', $country)
            ->whereBetween('financial_entries.created_at', [$from, $to])->sum('financial_entries.amount_minor');
        $rewards = Reward::query()->where('country_id', $country)->whereBetween('created_at', [$from, $to]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'platform_revenue_minor' => $revenue,
            'spent_minor' => (int) Ad::query()->where('country_id', $country)->sum('spent_minor'),
            'rewards_paid_minor' => (int) (clone $rewards)->whereNotNull('released_at')->sum('amount_minor'),
            'rewards_waiting_minor' => (int) Reward::query()->where('country_id', $country)->whereNull('released_at')->sum('amount_minor'),
            'ad_wallets_free_minor' => (int) DB::table('we_ad_wallets')->where('country_id', $country)->selectRaw('COALESCE(SUM(reward_minor + spend_only_minor + withdrawable_minor),0) s')->value('s'),
            'ads_locked_minor' => (int) Ad::query()->where('country_id', $country)
                ->whereIn('status', array_map(fn (AdStatus $s) => $s->value, array_filter(AdStatus::cases(), fn (AdStatus $s) => $s->holdsBudget())))
                ->selectRaw('COALESCE(SUM(reserved_reward_minor + reserved_spend_only_minor + reserved_withdrawable_minor),0) s')->value('s'),
            'completed_views' => (int) DB::table('we_views')->join('we_ads', 'we_ads.id', '=', 'we_views.ad_id')
                ->where('we_ads.country_id', $country)->whereBetween('we_views.completed_at', [$from, $to])->count(),
        ];
    }

    private function respond(Request $request, Ad $ad)
    {
        $request->attributes->set('we_admin', true);

        return ApiResponse::success((new AdResource($ad->load(['user', 'cities', 'country.currency'])))->resolve($request), __('api.updated'));
    }

    private function reportRows(Ad $ad, int $limit): array
    {
        return AdReport::query()->where('ad_id', $ad->id)->with('user:id,name,phone')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AdReport $r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'note' => $r->note,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'phone' => $r->user->phone] : null,
                'created_at' => $r->created_at?->toISOString(),
            ])->all();
    }
}
