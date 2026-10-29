<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Modules\User\Models\User;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSanction as Sanction;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Moderation\QualityScore;
use Modules\Freelance\Services\Moderation\Sanctions;

/**
 * Warnings, limits and suspensions for staff (FL-708), and the quality score / risk level of one person (FL-707). Permissions:
 * `freelance-sanctions.view`, `.issue` (warnings and limits), `.suspend` (issuing or lifting a suspension, on top of `.issue`),
 * `.appeal` (deciding appeals - never one's own sanction).
 */
class FreelanceSanctionAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly Sanctions $sanctions, private readonly QualityScore $quality) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-sanctions', [
            ['view', ['index', 'people', 'person', 'clients']],
            ['issue', ['store', 'lift']],
            ['appeal', ['decideAppeal']],
        ]);
    }

    /** GET freelance-sanctions[?state=active|lifted|expired&appeal=open&type=&side=&search=&per_page=] — `counts` for the tabs. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'state' => ['nullable', Rule::in(['active', 'lifted', 'expired'])],
            'appeal' => ['nullable', Rule::in(['open'])],
            'type' => ['nullable', Rule::in(Sanction::TYPES)],
            'side' => ['nullable', Rule::in(['client', 'freelancer'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = Sanction::query()
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['side'] ?? null, fn ($q, $s) => $q->where('subject_type', $s))
            ->when($f['search'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $match = fn ($m) => $m->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                $q->where(fn ($w) => $w->where(fn ($x) => $x->where('subject_type', 'freelancer')->whereIn('subject_id', Freelancer::query()->where($match)->select('id')))
                    ->orWhere(fn ($x) => $x->where('subject_type', 'client')->whereIn('subject_id', User::query()->where($match)->select('id'))));
            });
        $state = fn ($q, string $s) => match ($s) {
            'active' => $q->active(),
            'lifted' => $q->whereNotNull('lifted_at'),
            'expired' => $q->whereNull('lifted_at')->whereNotNull('ends_at')->where('ends_at', '<=', now()),
        };
        $counts = [
            'active' => $state(clone $base, 'active')->count(), 'lifted' => $state(clone $base, 'lifted')->count(),
            'expired' => $state(clone $base, 'expired')->count(), 'appeals' => (clone $base)->where('appeal_status', 'open')->count(),
        ];
        $page = (clone $base)->when($f['state'] ?? null, fn ($q, $s) => $state($q, $s))
            ->when(($f['appeal'] ?? null) === 'open', fn ($q) => $q->where('appeal_status', 'open'))
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $names = $this->sanctions->names($page->getCollection());

        return ApiResponse::success(
            $page->getCollection()->map(fn (Sanction $s) => $this->sanctions->present($s, true, $names))->values(),
            __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts],
        );
    }

    /** GET freelance-sanctions/people?side=&search= — to pick the person a sanction is for. */
    public function people(Request $request): JsonResponse
    {
        $f = $request->validate(['side' => ['required', Rule::in(['client', 'freelancer'])], 'search' => ['required', 'string', 'min:2', 'max:100']]);
        $like = '%'.$f['search'].'%';
        $q = $f['side'] === 'freelancer' ? Freelancer::query() : User::query();

        return ApiResponse::success($q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
            ->limit(10)->get(['id', 'name', 'phone'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'phone' => $p->phone])->values(), __('api.retrieved'));
    }

    /** GET freelance-sanctions/clients[?risk=low|medium|high&search=&per_page=] (FL-707) — clients who started projects, their quality and risk; `counts` per level. */
    public function clients(Request $request): JsonResponse
    {
        $f = $request->validate(['risk' => ['nullable', Rule::in(['low', 'medium', 'high'])], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $base = DB::table('freelance_client_risk as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->when($f['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('u.name', 'like', '%'.$term.'%')->orWhere('u.phone', 'like', '%'.$term.'%')));
        $counts = (clone $base)->selectRaw('r.risk_level, count(*) as n')->groupBy('r.risk_level')->pluck('n', 'risk_level')->map(fn ($n) => (int) $n)->all();
        $page = (clone $base)->when($f['risk'] ?? null, fn ($q, $l) => $q->where('r.risk_level', $l))
            ->orderByRaw("case r.risk_level when 'high' then 0 when 'medium' then 1 else 2 end")->orderBy('r.quality_score')
            ->paginate((int) ($f['per_page'] ?? 20), ['u.id', 'u.name', 'u.phone', 'r.quality_score', 'r.risk_level', 'r.checked_at']);

        return ApiResponse::success(collect($page->items())->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'phone' => $c->phone, 'quality_score' => $c->quality_score === null ? null : (int) $c->quality_score,
            'risk_level' => $c->risk_level, 'checked_at' => $c->checked_at,
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts]);
    }

    /** GET freelance-sanctions/person/{side}/{id} — one person: quality and risk (FL-707), what limits them now, and every sanction. */
    public function person(string $side, int $id): JsonResponse
    {
        abort_unless(in_array($side, ['client', 'freelancer'], true), 404);
        $person = $this->sanctions->person($side, $id);
        $quality = $this->quality->refresh($person);
        $list = Sanction::query()->for($side, $id)->latest('id')->limit(50)->get();
        $names = $this->sanctions->names($list);

        return ApiResponse::success([
            'side' => $side, 'id' => $id, 'name' => $person->name, 'phone' => $person->phone,
            'quality' => $quality, 'restrictions' => $this->sanctions->restrictions($person),
            'sanctions' => $list->map(fn (Sanction $s) => $this->sanctions->present($s, true, $names))->values()->all(),
        ], __('api.retrieved'));
    }

    /** POST freelance-sanctions {side, person_id, type, reason, days?, source?, source_ref?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'side' => ['required', Rule::in(['client', 'freelancer'])],
            'person_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(Sanction::TYPES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'source' => ['nullable', Rule::in(Sanction::SOURCES)],
            'source_ref' => ['nullable', 'string', 'max:64'],
        ]);
        $admin = $this->admin($request);
        if ($data['type'] === Sanction::SUSPENSION) {
            $this->assertCanSuspend($admin);
        }
        $s = $this->sanctions->issue($this->sanctions->person($data['side'], (int) $data['person_id']), $data['type'], $data['reason'], $data['days'] ?? null, $data['source'] ?? 'manual', $data['source_ref'] ?? null, $admin);

        return ApiResponse::created($this->present($s), __('freelance.sanction_issued'));
    }

    /** POST freelance-sanctions/{uuid}/lift {note?} */
    public function lift(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $s = Sanction::query()->where('uuid', $uuid)->firstOrFail();
        $admin = $this->admin($request);
        if ($s->type === Sanction::SUSPENSION) {
            $this->assertCanSuspend($admin);
        }

        return ApiResponse::success($this->present($this->sanctions->lift($s, $admin, $data['note'] ?? null)), __('api.updated'));
    }

    /** POST freelance-sanctions/{uuid}/appeal {decision: upheld|reduced|lifted, ends_at? (for reduced), note?} */
    public function decideAppeal(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'reduced', 'lifted'])],
            'ends_at' => ['nullable', 'required_if:decision,reduced', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $s = Sanction::query()->where('uuid', $uuid)->firstOrFail();
        $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay() : null;

        return ApiResponse::success($this->present($this->sanctions->decideAppeal($s, $this->admin($request), $data['decision'], $endsAt, $data['note'] ?? null)), __('api.updated'));
    }

    private function admin(Request $request): Admin
    {
        return $request->user('admin_api');
    }

    private function assertCanSuspend(Admin $admin): void
    {
        if (! $admin->can('freelance-sanctions.suspend')) {
            throw new FreelanceException('sanction_suspend_permission', 403);
        }
    }

    /** @return array<string, mixed> */
    private function present(Sanction $s): array
    {
        return $this->sanctions->present($s->refresh(), true, $this->sanctions->names([$s]));
    }
}
