<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Reports\FreelanceLateWork;

/**
 * Staff's view of the work (FL-801 / FL-802): every project, offer and agreement with filters and counts per status, and what is late.
 * Read only: the decisions stay in their own desks (review, disputes, sanctions).
 */
class FreelanceOversightController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelanceLateWork $late, private readonly FreelanceFeatures $features) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-projects', [
            ['view', ['projects', 'offers', 'agreements', 'late', 'countries']],
        ]);
    }

    private static function value(mixed $status): ?string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : ($status === null ? null : (string) $status);
    }

    /** GET freelance-oversight/projects[?status=&country_id=&q=&per_page=] — `counts` per status (for the country when given). */
    public function projects(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'country_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $base = FreelanceProject::query()
            ->when(isset($f['country_id']), fn ($q) => $q->where('country_id', $f['country_id']))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$f['q'].'%')->orWhere('uuid', $f['q'])));
        $page = (clone $base)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->with('client:id,name')->withCount('offers')->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceProject $p) => [
            'uuid' => $p->uuid, 'title' => $p->title, 'status' => self::value($p->status), 'visibility' => $p->visibility,
            'country_id' => $p->country_id, 'client' => ['id' => $p->client?->id, 'name' => $p->client?->name],
            'budget_min_minor' => $p->budget_min_minor, 'budget_max_minor' => $p->budget_max_minor, 'currency' => $p->currency_code,
            'offers_count' => $p->offers_count, 'published_at' => $p->published_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()]);
    }

    /** GET freelance-oversight/offers[?status=&project=uuid&freelancer_id=&per_page=] */
    public function offers(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'project' => ['nullable', 'uuid'], 'freelancer_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $base = FreelanceOffer::query()
            ->when(isset($f['project']), fn ($q) => $q->whereHas('project', fn ($p) => $p->where('uuid', $f['project'])))
            ->when(isset($f['freelancer_id']), fn ($q) => $q->where('freelancer_id', $f['freelancer_id']));
        $page = (clone $base)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->with(['project:id,uuid,title', 'freelancer:id,name'])->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceOffer $o) => [
            'uuid' => $o->uuid, 'status' => self::value($o->status), 'price_minor' => $o->price_minor, 'currency' => $o->currency_code, 'duration_days' => $o->duration_days,
            'project' => ['uuid' => $o->project?->uuid, 'title' => $o->project?->title], 'freelancer' => ['id' => $o->freelancer?->id, 'name' => $o->freelancer?->name],
            'created_at' => $o->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()]);
    }

    /** GET freelance-oversight/agreements[?status=&project=uuid&per_page=] — with how far the parts are. */
    public function agreements(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'project' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $base = FreelanceAgreement::query()->when(isset($f['project']), fn ($q) => $q->whereHas('project', fn ($p) => $p->where('uuid', $f['project'])));
        $page = (clone $base)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->with(['project:id,uuid,title', 'client:id,name', 'freelancer:id,name', 'milestones:id,agreement_id,status'])->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceAgreement $a) => [
            'uuid' => $a->uuid, 'status' => self::value($a->status), 'price_minor' => $a->price_minor, 'currency' => $a->currency_code, 'duration_days' => $a->duration_days,
            'project' => ['uuid' => $a->project?->uuid, 'title' => $a->project?->title],
            'client' => ['id' => $a->client?->id, 'name' => $a->client?->name], 'freelancer' => ['id' => $a->freelancer?->id, 'name' => $a->freelancer?->name],
            'parts' => ['total' => $a->milestones->count(), 'approved' => $a->milestones->filter(fn ($m) => self::value($m->status) === 'approved')->count()],
            'activated_at' => $a->activated_at?->toIso8601String(), 'expires_at' => $a->expires_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()]);
    }

    /**
     * GET freelance-oversight/countries (FL-804) — where the service is open: per country, its freelance switch, whether the dashboard's
     * `service-availability` page closed the service there, what the apps are allowed to show, and how much work there is.
     */
    public function countries(): JsonResponse
    {
        $projects = DB::table('freelance_projects')->select('country_id', DB::raw('count(*) as n'))->groupBy('country_id')->pluck('n', 'country_id');
        $rows = \App\Models\Country::query()->with('currency')->orderBy('id')->get()->map(function (\App\Models\Country $c) use ($projects) {
            $snapshot = $this->features->snapshot($c->id);

            return [
                'id' => $c->id, 'code' => $c->code, 'currency' => $c->currency?->code,
                'service_open' => $snapshot['service'], 'closed_by_availability' => $this->features->closedByAvailability($c->id),
                'features' => $snapshot['features'], 'projects' => (int) ($projects[$c->id] ?? 0),
            ];
        })->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    /** GET freelance-oversight/late — what is late, grouped; `total` is the number of items. */
    public function late(): JsonResponse
    {
        $groups = $this->late->groups();

        return ApiResponse::success(['total' => array_sum(array_map('count', $groups)), 'groups' => $groups, 'checked_at' => now()->toIso8601String()], __('api.retrieved'));
    }
}
