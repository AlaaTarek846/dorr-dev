<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceSkillChallenge;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Models\FreelancerSkillBadge;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\Reputation\FreelancerReputation;
use Modules\Freelance\Services\Skills\FreelanceSkillChallenges;

/**
 * FS-605: the quality desk (`freelance-quality`), at `admin/v1/freelance-quality/…`:
 *  - `summary` — what waits in each queue.
 *  - `verified-works` — the works «Verified by Dorr» (FS-601) and the ones whose badge staff took back; `…/{id}/unverify` {reason}, `…/{id}/reverify`.
 *  - `challenges` — the practical challenges for advanced / expert (FS-602); `…/{uuid}/decide` {decision: accept|reject, level?, note?}.
 *  - `corrections` — the reputation data a freelancer asked to correct (FS-604); `…/{uuid}/decide` {decision: accept|reject, note?}.
 * The checklist templates and the reported messages keep their own screens.
 */
class FreelanceQualityController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly FreelancerPortfolio $portfolio,
        private readonly FreelanceSkillChallenges $challenges,
        private readonly FreelancerReputation $reputation,
    ) {}

    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-quality', [
            ['view', ['summary', 'works', 'challenges', 'corrections']],
            ['update', ['unverify', 'reverify', 'decideChallenge', 'decideCorrection']],
        ]);
    }

    public function summary(): JsonResponse
    {
        return ApiResponse::success([
            'verified_works' => $this->worksQuery('verified')->count(),
            'challenges_waiting' => $this->challenges->counts()['submitted'],
            'corrections_open' => $this->reputation->queue('open', null, 1)[1]['open'],
            'levels' => FreelancerSkillBadge::query()->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level'),
        ], __('api.retrieved'));
    }

    // -------------------------------------------------------------- verified works (FS-601)

    /** GET verified-works?status=verified|revoked&search=&per_page= */
    public function works(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['verified', 'revoked'])], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->worksQuery($f['status'] ?? 'verified', $f['search'] ?? null)
            ->with(['freelancer:id,uuid,name,phone', 'agreement:id,uuid,status,portfolio_allowed,completed_at,project_id', 'agreement.project:id,uuid,title', 'media', 'specialty.translations'])
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (Item $i) => $this->presentWork($i))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + [
            'counts' => ['verified' => $this->worksQuery('verified')->count(), 'revoked' => $this->worksQuery('revoked')->count()],
        ]);
    }

    public function unverify(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $model = $this->portfolio->unverify(Item::query()->findOrFail($item), $data['reason'], (int) $request->user('admin_api')->getKey());

        return ApiResponse::success($this->presentWork($model->load(['freelancer', 'agreement.project'])), __('freelance.quality_decided'));
    }

    public function reverify(Request $request, int $item): JsonResponse
    {
        $model = $this->portfolio->reverify(Item::query()->findOrFail($item), (int) $request->user('admin_api')->getKey());

        return ApiResponse::success($this->presentWork($model->load(['freelancer', 'agreement.project'])), __('freelance.quality_decided'));
    }

    // -------------------------------------------------------------- challenges (FS-602)

    /** GET challenges?status=&search=&per_page= */
    public function challenges(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(FreelanceSkillChallenge::STATUSES)], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->challenges->queue($f['status'] ?? null, $f['search'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($c) => $this->challenges->present($c, true))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page) + ['counts' => $this->challenges->counts()]);
    }

    public function decideChallenge(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'level' => ['nullable', Rule::in(FreelanceSkillChallenge::TARGETS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $c = $this->challenges->decide($uuid, (int) $request->user('admin_api')->getKey(), $data['decision'], $data['level'] ?? null, $data['note'] ?? null);

        return ApiResponse::success($this->challenges->present($c, true), __('freelance.quality_decided'));
    }

    // -------------------------------------------------------------- reputation corrections (FS-604)

    /** GET corrections?status=open|accepted|rejected&search=&per_page= */
    public function corrections(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'accepted', 'rejected'])], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        [$page, $counts] = $this->reputation->queue($f['status'] ?? null, $f['search'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->values(), __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts]);
    }

    public function decideCorrection(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success($this->reputation->decide($uuid, (int) $request->user('admin_api')->getKey(), $data['decision'], $data['note'] ?? null), __('freelance.quality_decided'));
    }

    // -------------------------------------------------------------- inside

    private function worksQuery(string $status, ?string $search = null)
    {
        return Item::query()->whereNotNull('agreement_id')
            ->whereHas('agreement', fn ($a) => $a->where('status', FreelanceAgreement::COMPLETED)->where('portfolio_allowed', true))
            ->when($status === 'verified', fn ($q) => $q->whereNull('verified_revoked_at'), fn ($q) => $q->whereNotNull('verified_revoked_at'))
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')
                ->orWhereHas('freelancer', fn ($p) => $p->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%'))));
    }

    /** @return array<string, mixed> */
    private function presentWork(Item $i): array
    {
        return $this->portfolio->present($i, false) + [
            'id' => $i->id,
            'freelancer' => $i->freelancer === null ? null : ['id' => $i->freelancer->id, 'uuid' => $i->freelancer->uuid, 'name' => $i->freelancer->name, 'phone' => $i->freelancer->phone],
            'project' => $i->agreement?->project === null ? null : ['uuid' => $i->agreement->project->uuid, 'title' => $i->agreement->project->title],
            'hidden' => $i->isHidden(),
            'visibility' => $i->visibility,
            'revoked_reason' => $i->verified_revoked_reason,
            'revoked_at' => $i->verified_revoked_at?->toIso8601String(),
        ];
    }
}
