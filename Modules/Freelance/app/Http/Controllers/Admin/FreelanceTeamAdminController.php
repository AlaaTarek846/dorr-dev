<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAssignments;
use Modules\Freelance\Services\FreelancerTeam\TeamModeration;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;
use Modules\Freelance\Services\FreelancerTeam\TeamSubscriptions;
use Modules\Freelance\Services\FreelancerTeam\TeamVerification;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin -> Freelance -> Teams (FS-208): find the freelancers' teams, open one (people, projects, offers, what each member carries, limits, the
 * staff log) and suspend it with a reason, bring it back, or take somebody out. The per-country switch (`teams`) and the limits
 * (`teams.*`) are the existing switches and policies screens.
 */
class FreelanceTeamAdminController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TeamModeration $moderation,
        private readonly TeamVerification $verification,
        private readonly \App\Services\General\AuditLogger $audit,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-teams', [
            ['view', ['index', 'show', 'options', 'verificationFile']],
            ['update', ['status', 'removeMember', 'verificationDecide', 'verificationRevoke']],
        ]);
    }

    /** GET freelance-teams/options */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => \App\Models\Country::query()->with('translations')->where('status', true)->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'statuses' => FreelanceTeam::STATUSES,
        ], __('api.retrieved'));
    }

    /** GET freelance-teams[?search=&status=&country_id=&per_page=] - `counts` per status for the tabs. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(FreelanceTeam::STATUSES)],
            'country_id' => ['nullable', 'integer'],
            'verification' => ['nullable', Rule::in(['pending', 'verified'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = FreelanceTeam::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $request->string('search'), '%_\\').'%'))
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')));
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $pending = \Modules\Freelance\Models\FreelanceTeamVerificationRequest::SUBMITTED;
        $page = (clone $base)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->string('verification')->toString() === 'pending', fn ($q) => $q->whereHas('verificationRequests', fn ($r) => $r->where('status', $pending)))
            ->when($request->string('verification')->toString() === 'verified', fn ($q) => $q->whereNotNull('verified_at'))
            ->with(['country', 'creator:id,name'])->latest('id')->paginate($request->integer('per_page', 20));

        $ids = $page->getCollection()->pluck('id');
        $members = Member::query()->whereIn('team_id', $ids)->where('status', Member::ACTIVE)->selectRaw('team_id, count(*) as n')->groupBy('team_id')->pluck('n', 'team_id');
        $running = FreelanceAgreement::query()->whereIn('team_id', $ids)->where('status', FreelanceAgreement::ACTIVE)->selectRaw('team_id, count(*) as n')->groupBy('team_id')->pluck('n', 'team_id');
        $waiting = \Modules\Freelance\Models\FreelanceTeamVerificationRequest::query()->whereIn('team_id', $ids)->where('status', $pending)->pluck('team_id')->flip();

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceTeam $t) => $this->present($t) + [
            'members_count' => (int) ($members[$t->id] ?? 0),
            'projects_running' => (int) ($running[$t->id] ?? 0),
            'verification_pending' => $waiting->has($t->id),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts, 'verification_pending' => FreelanceTeam::query()->whereHas('verificationRequests', fn ($r) => $r->where('status', $pending))->count()]);
    }

    /** GET freelance-teams/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->with(['country', 'creator:id,name'])->firstOrFail();
        $admins = Admin::query()->pluck('name', 'id');
        $audit = AuditLog::query()->where('subject_type', $team->getMorphClass())->where('subject_id', $team->id)->latest('id')->limit(30)->get();
        $agreements = FreelanceAgreement::query()->where('team_id', $team->id)->where('status', '!=', FreelanceAgreement::DRAFT)->with(['project:id,uuid,title', 'freelancer:id,uuid,name'])->latest('id')->limit(30)->get();

        return ApiResponse::success($this->present($team) + [
            'teams_enabled' => $team->country_id === null ? null : app(FreelanceFeatures::class)->allows((int) $team->country_id, 'teams'),
            'members_max' => app(FreelancerTeams::class)->maxMembers($team),
            // FS-211: the team's plan (or the free baseline), what it holds against its room, and its subscription with who pays.
            'plan' => ['usage' => app(TeamPlans::class)->usage($team), 'subscription' => app(TeamSubscriptions::class)->mine($team)],
            'members' => Member::query()->where('team_id', $team->id)->with('freelancer:id,uuid,name,phone')->orderByRaw("case status when 'active' then 0 else 1 end")->orderBy('id')->get()->map(fn (Member $m) => [
                'freelancer_id' => $m->freelancer_id, 'uuid' => $m->freelancer?->uuid, 'name' => $m->freelancer?->name, 'phone' => $m->freelancer?->phone,
                'role' => $m->role, 'status' => $m->status, 'joined_at' => $m->joined_at?->toIso8601String(), 'left_at' => $m->left_at?->toIso8601String(), 'left_reason' => $m->left_reason,
                'removed_by_staff' => $m->status === Member::REMOVED && $m->removed_by_freelancer_id === null,
            ])->values()->all(),
            'workload' => app(TeamAssignments::class)->workload($team),
            'projects' => $agreements->map(fn (FreelanceAgreement $a) => [
                'uuid' => $a->uuid, 'project' => ['uuid' => $a->project?->uuid, 'title' => $a->project?->title], 'status' => $a->status,
                'signed_by' => $a->freelancer?->name, 'price_minor' => (int) ($a->money['price_minor'] ?? 0), 'currency' => $a->currency_code,
            ])->values()->all(),
            'offers' => FreelanceOffer::query()->where('team_id', $team->id)->with('project:id,uuid,title')->latest('id')->limit(20)->get()->map(fn (FreelanceOffer $o) => [
                'uuid' => $o->uuid, 'project' => ['uuid' => $o->project?->uuid, 'title' => $o->project?->title], 'price_minor' => (int) $o->price_minor, 'currency' => $o->currency_code,
                'status' => $o->status instanceof \BackedEnum ? $o->status->value : $o->status, 'created_at' => $o->created_at?->toIso8601String(),
            ])->values()->all(),
            'verification' => $this->verification->forAdmin($team),
            'stats' => [
                'agreements_active' => FreelanceAgreement::query()->where('team_id', $team->id)->where('status', FreelanceAgreement::ACTIVE)->count(),
                'agreements_completed' => FreelanceAgreement::query()->where('team_id', $team->id)->where('status', FreelanceAgreement::COMPLETED)->count(),
                'offers' => FreelanceOffer::query()->where('team_id', $team->id)->count(),
            ],
            'audit' => $audit->map(fn (AuditLog $l) => [
                'action' => $l->action, 'by' => $l->actor_type === 'admin' ? ($admins[$l->actor_id] ?? '#'.$l->actor_id) : 'system',
                'before' => $l->before, 'after' => $l->after, 'at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ], __('api.retrieved'));
    }

    /** POST freelance-teams/{uuid}/status {status: active|suspended, reason} - a reason is needed to suspend. */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([FreelanceTeam::ACTIVE, FreelanceTeam::SUSPENDED])],
            'reason' => ['nullable', 'string', 'max:'.TeamModeration::REASON_MAX],
        ]);
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success($this->present($this->moderation->setStatus($team, $data['status'], $data['reason'] ?? null)->load(['country', 'creator:id,name'])), __('api.updated'));
    }

    /** POST freelance-teams/{uuid}/members/{freelancer}/remove {reason?} */
    public function removeMember(Request $request, string $uuid, string $freelancer): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:'.FreelancerTeams::REASON_MAX]]);
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        $target = Freelancer::query()->where('uuid', $freelancer)->firstOrFail();
        $this->moderation->removeMember($team, $target, $data['reason'] ?? null);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** GET freelance-teams/{uuid}/verification/documents/{document} - opening a paper is itself audited. */
    public function verificationFile(string $uuid, string $document): BinaryFileResponse
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        $doc = $this->verification->documentFor($team, $document);
        $media = $doc->file() ?? abort(404);
        $this->audit->record('freelance.team.verification_file_opened', $team, null, ['document' => $doc->uuid]);

        return tap(response()->file($media->getPath(), ['Content-Type' => $media->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** POST freelance-teams/{uuid}/verification/decide {decision: approve|reject, reason?} - on the open request; a reason is needed to reject. */
    public function verificationDecide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'string', 'max:'.TeamVerification::REASON_MAX]]);
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        $open = \Modules\Freelance\Models\FreelanceTeamVerificationRequest::query()->where('team_id', $team->id)
            ->where('status', \Modules\Freelance\Models\FreelanceTeamVerificationRequest::SUBMITTED)->latest('id')->first()
            ?? throw new \Modules\Freelance\Exceptions\FreelanceException('team_verification_decided', 409);
        $this->verification->decide($open, $data['decision'], $data['reason'] ?? null, (int) $request->user('admin_api')->getKey());

        return ApiResponse::success($this->verification->forAdmin($team->refresh()), __('api.updated'));
    }

    /** POST freelance-teams/{uuid}/verification/revoke {reason} - takes the badge back. */
    public function verificationRevoke(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.TeamVerification::REASON_MAX]]);
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        $this->verification->revoke($team, $data['reason'], (int) $request->user('admin_api')->getKey());

        return ApiResponse::success($this->verification->forAdmin($team->refresh()), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(FreelanceTeam $t): array
    {
        return [
            'uuid' => $t->uuid, 'name' => $t->name, 'status' => $t->status, 'status_reason' => $t->status_reason,
            'status_changed_at' => $t->status_changed_at?->toIso8601String(),
            'verified' => $t->verified_at !== null, 'country' => $t->country?->code, 'created_by' => $t->creator?->name, 'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
