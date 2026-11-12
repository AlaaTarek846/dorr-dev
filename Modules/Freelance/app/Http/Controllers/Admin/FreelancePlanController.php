<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Models\Country;
use App\Models\Currency;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\FreelanceSubscriptionInvoice;
use Modules\Freelance\Models\FreelanceTeamSubscription;
use Modules\Freelance\Models\FreelanceTeamSubscriptionInvoice;
use Modules\Freelance\Services\Plans\FreelanceOfferPackDefaults;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;

/**
 * The plans desk (FL-L01): what each plan gives, its price in every country (monthly and yearly), who is subscribed, and the money the
 * plans brought in. Free is shown but its numbers are fixed (it follows each country's policy); the prices of a country can be filled
 * from the defaults when a country is new. FS-211: the same desk holds the teams' plans (`audience` team: the room they open - people,
 * projects, offers - and the teams subscribed), told apart by `audience`; their prices work exactly like a freelancer plan's.
 */
class FreelancePlanController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelancePlanDefaults $defaults, private readonly AuditLogger $audit) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-plans', [
            ['view', ['index', 'prices', 'subscriptions']],
            ['update', ['update', 'setPrice', 'fillPrices']],
        ]);
    }

    /** GET freelance-plans — the plans, with who is on each and the money of the last 30 days (per currency). */
    public function index(): JsonResponse
    {
        // A plan belongs to one audience, so its plan id is in one of the two tables only.
        $freelancerSubs = FreelanceSubscription::query()->live()->select('plan_id', DB::raw('count(*) as n'))->groupBy('plan_id')->pluck('n', 'plan_id');
        $teamSubs = FreelanceTeamSubscription::query()->live()->select('plan_id', DB::raw('count(*) as n'))->groupBy('plan_id')->pluck('n', 'plan_id');
        $subs = $freelancerSubs->union($teamSubs);
        $revenue = collect([FreelanceSubscriptionInvoice::class, FreelanceTeamSubscriptionInvoice::class])
            ->flatMap(fn (string $invoice) => $invoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))
                ->select('currency_code', DB::raw('sum(amount_minor) as total'), DB::raw('count(*) as n'))->groupBy('currency_code')->get())
            ->groupBy('currency_code')
            ->map(fn ($rows, $code) => ['currency' => $code, 'decimals' => $this->decimals($code), 'total_minor' => (int) $rows->sum('total'), 'invoices' => (int) $rows->sum('n')])->values();

        return ApiResponse::success([
            'plans' => FreelancePlan::query()->orderBy('audience')->orderBy('rank')->get()->map(fn (FreelancePlan $p) => $this->plan($p) + ['subscribers' => (int) ($subs[$p->id] ?? 0)])->values(),
            'revenue_30d' => $revenue,
            'subscribers' => (int) $freelancerSubs->sum(),
            'team_subscribers' => (int) $teamSubs->sum(),
            'defaults' => ['yearly_discount_bp' => (int) config('freelance.plan_yearly_discount_bp'), 'grace_days' => (int) config('freelance.plan_grace_days')],
        ], __('api.retrieved'));
    }

    /** PUT freelance-plans/{plan} — what the plan gives (the Free plan's numbers stay empty: the policy rules). */
    public function update(Request $request, int $plan): JsonResponse
    {
        $model = FreelancePlan::query()->findOrFail($plan);
        $data = $request->validate([
            'name' => ['sometimes', 'array'], 'name.ar' => ['required_with:name', 'string', 'max:60'], 'name.en' => ['required_with:name', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'array'], 'description.ar' => ['nullable', 'string', 'max:300'], 'description.en' => ['nullable', 'string', 'max:300'],
            'offers_monthly' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'], 'offers_unlimited' => ['sometimes', 'boolean'],
            'commission_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'], 'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'status' => ['sometimes', 'boolean'],
            // FL-L01 phase 4: the badge, the weight in the «recommended» order, the numbers (0 none, 1 basic, 2 full) and the ready-made services allowed at once.
            'badge' => ['sometimes', 'boolean'],
            'boost' => ['sometimes', 'integer', 'min:0', 'max:'.(int) config('freelance.plan_boost_max', 3)],
            'analytics' => ['sometimes', 'integer', 'min:0', 'max:2'],
            // FS-703: the freelancer's CRM (0 none, 1 clients + notes, 2 also labels and follow-up reminders).
            'crm' => ['sometimes', 'integer', 'min:0', 'max:2'],
            'services_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            // FS-211 (a team plan): the people and the running projects it allows; empty = no cap.
            'team_members_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'team_projects_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        if ($model->isTeam()) {
            // A team plan has no commission, badge, boost, analytics or services: those are a freelancer's.
            unset($data['commission_bp'], $data['badge'], $data['boost'], $data['analytics'], $data['crm'], $data['services_max']);
        } else {
            unset($data['team_members_max'], $data['team_projects_max']);
        }
        if ($model->isFree()) {
            // Free follows the policy and is always on.
            unset($data['offers_monthly'], $data['offers_unlimited'], $data['commission_bp'], $data['trial_days'], $data['status'], $data['badge'], $data['boost'], $data['analytics'], $data['crm'], $data['services_max']);
        }
        $before = $model->only(array_keys($data));
        $model->update($data);
        $this->audit->record('freelance_plan.updated', $model, $before, $model->only(array_keys($data)));

        return ApiResponse::success($this->plan($model->refresh()), __('api.updated'));
    }

    /** GET freelance-plans/{plan}/prices[?search=&per_page=&page=] — every country with this plan's two prices (empty where not set). */
    public function prices(Request $request, int $plan): JsonResponse
    {
        $model = FreelancePlan::query()->findOrFail($plan);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'missing' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $set = FreelancePlanPrice::query()->where('plan_id', $model->id)->get()->groupBy('country_id');

        // One price for the whole world (`freelance.money`): only the international row is listed and priced.
        $query = Country::query()->with(['currency', 'translations'])->whereKey(app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->countryId())->orderBy('code')
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$f['search'].'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$f['search'].'%'))))
            ->when(! empty($f['missing']), fn ($q) => $q->whereNotIn('id', FreelancePlanPrice::query()->where('plan_id', $model->id)->select('country_id')));
        $page = $query->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(function (Country $c) use ($set) {
            $rows = ($set[$c->id] ?? collect())->keyBy('period');

            return [
                'country_id' => $c->id, 'code' => $c->code, 'name' => $c->translations->firstWhere('locale', app()->getLocale())?->name ?? $c->translations->first()?->name ?? $c->code,
                'currency' => $c->currency?->code, 'decimals' => (int) ($c->currency?->decimal_places ?? 2),
                'monthly' => isset($rows['monthly']) ? ['price_minor' => $rows['monthly']->price_minor, 'status' => $rows['monthly']->status] : null,
                'yearly' => isset($rows['yearly']) ? ['price_minor' => $rows['yearly']->price_minor, 'status' => $rows['yearly']->status] : null,
            ];
        })->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** PUT freelance-plans/{plan}/prices/{country} {monthly_minor, yearly_minor, status} — set a country's prices. */
    public function setPrice(Request $request, int $plan, int $country): JsonResponse
    {
        $model = FreelancePlan::query()->findOrFail($plan);
        if ($model->isFree()) {
            throw new FreelanceException('plan_free_no_price', 422);
        }
        abort_unless(app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->isInternational($country), 404);
        $c = Country::query()->findOrFail($country);
        $data = $request->validate([
            'monthly_minor' => ['required', 'integer', 'min:1', 'max:100000000000'], 'yearly_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'status' => ['sometimes', 'boolean'],
        ]);
        // A year must cost less than twelve months, or nobody would take it.
        if ($data['yearly_minor'] > $data['monthly_minor'] * 12) {
            throw new FreelanceException('plan_yearly_too_high', 422);
        }

        $before = FreelancePlanPrice::query()->where('plan_id', $model->id)->where('country_id', $c->id)->pluck('price_minor', 'period')->all();
        foreach (['monthly' => 'monthly_minor', 'yearly' => 'yearly_minor'] as $period => $key) {
            FreelancePlanPrice::query()->updateOrCreate(
                ['plan_id' => $model->id, 'country_id' => $c->id, 'period' => $period],
                ['price_minor' => $data[$key]] + (isset($data['status']) ? ['status' => $data['status']] : ['status' => true]),
            );
        }
        $this->audit->record('freelance_plan.price_set', $model, $before, ['country_id' => $c->id, 'monthly' => $data['monthly_minor'], 'yearly' => $data['yearly_minor']]);

        return ApiResponse::success(['country_id' => $c->id, 'monthly_minor' => $data['monthly_minor'], 'yearly_minor' => $data['yearly_minor']], __('api.updated'));
    }

    /** POST freelance-plans/fill-prices — the plans and every missing price from the defaults (a new country); prices already set stay. */
    public function fillPrices(): JsonResponse
    {
        $packs = app(FreelanceOfferPackDefaults::class)->seed();

        return ApiResponse::success($this->defaults->seed() + ['packs' => $packs['packs'], 'pack_prices' => $packs['prices']], __('api.updated'));
    }

    /**
     * GET freelance-plans/subscriptions[?audience=&status=&plan=&search=&per_page=] — who is subscribed, with counts per status. `audience=team`
     * (FS-211) lists the teams on a team plan instead, with the Owner who pays.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $f = $request->validate([
            'audience' => ['nullable', Rule::in(FreelancePlan::AUDIENCES)], 'status' => ['nullable', Rule::in(['trialing', 'active', 'past_due', 'expired'])],
            'plan' => ['nullable', 'string', 'max:40'], 'search' => ['nullable', 'string', 'max:60'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (($f['audience'] ?? null) === FreelancePlan::TEAM) {
            return $this->teamSubscriptions($f);
        }
        $base = FreelanceSubscription::query()
            ->when(! empty($f['plan']), fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('slug', $f['plan'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('freelancer', fn ($p) => $p->where('name', 'like', '%'.$f['search'].'%')->orWhere('phone', 'like', '%'.$f['search'].'%')));
        $page = (clone $base)->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->with(['plan', 'freelancer:id,name,phone'])->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceSubscription $s) => [
            'uuid' => $s->uuid, 'status' => $s->status, 'period' => $s->period, 'auto_renew' => $s->auto_renew, 'price_minor' => $s->price_minor, 'currency' => $s->currency_code, 'decimals' => $this->decimals($s->currency_code),
            'plan' => ['slug' => $s->plan->slug, 'name' => $s->plan->text('name')], 'freelancer' => ['id' => $s->freelancer?->id, 'name' => $s->freelancer?->name, 'phone' => $s->freelancer?->phone],
            'started_at' => $s->started_at?->toIso8601String(), 'period_end' => $s->period_end?->toIso8601String(), 'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'grace_until' => $s->grace_until?->toIso8601String(), 'canceled_at' => $s->canceled_at?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()]);
    }

    /**
     * The teams on a team plan (FS-211): the team, the Owner who pays, the plan and when it renews.
     *
     * @param  array<string, mixed>  $f
     */
    private function teamSubscriptions(array $f): JsonResponse
    {
        $base = FreelanceTeamSubscription::query()
            ->when(! empty($f['plan']), fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('slug', $f['plan'])))
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w->whereHas('team', fn ($t) => $t->where('name', 'like', '%'.$f['search'].'%'))
                ->orWhereHas('payer', fn ($p) => $p->where('name', 'like', '%'.$f['search'].'%')->orWhere('phone', 'like', '%'.$f['search'].'%'))));
        $page = (clone $base)->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->with(['plan', 'team', 'payer:id,name,phone'])->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceTeamSubscription $s) => [
            'uuid' => $s->uuid, 'status' => $s->status, 'period' => $s->period, 'auto_renew' => $s->auto_renew, 'price_minor' => $s->price_minor, 'currency' => $s->currency_code, 'decimals' => $this->decimals($s->currency_code),
            'plan' => ['slug' => $s->plan->slug, 'name' => $s->plan->text('name')], 'team' => ['uuid' => $s->team?->uuid, 'name' => $s->team?->name],
            'freelancer' => ['id' => $s->payer?->id, 'name' => $s->payer?->name, 'phone' => $s->payer?->phone],
            'started_at' => $s->started_at?->toIso8601String(), 'period_end' => $s->period_end?->toIso8601String(), 'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'grace_until' => $s->grace_until?->toIso8601String(), 'canceled_at' => $s->canceled_at?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()]);
    }

    /** The decimal places of a currency code (prices are whole minor units; the screen needs to know where the point goes). */
    private function decimals(?string $code): int
    {
        static $places = null;
        $places ??= Currency::query()->pluck('decimal_places', 'code')->all();

        return (int) ($places[$code] ?? 2);
    }

    /** @return array<string, mixed> */
    private function plan(FreelancePlan $p): array
    {
        return [
            'id' => $p->id, 'slug' => $p->slug, 'audience' => $p->audience, 'rank' => $p->rank, 'free' => $p->isFree(), 'name' => $p->name, 'description' => $p->description,
            'offers_monthly' => $p->offers_monthly, 'offers_unlimited' => $p->offers_unlimited, 'commission_bp' => $p->commission_bp, 'trial_days' => $p->trial_days, 'status' => $p->status,
            'badge' => (bool) $p->badge, 'boost' => (int) $p->boost, 'analytics' => (int) $p->analytics, 'crm' => (int) $p->crm, 'services_max' => $p->services_max,
            'team_members_max' => $p->team_members_max, 'team_projects_max' => $p->team_projects_max,
        ];
    }
}
