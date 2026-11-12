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
use Illuminate\Validation\ValidationException;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillQuestion;
use Modules\Freelance\Models\FreelanceSkillTest;
use Modules\Freelance\Models\FreelancerSkillBadge;
use Modules\Freelance\Services\Skills\FreelanceSkillTests;

/**
 * Admin → Freelance → Skill tests (FL-L08): per skill, how its test is set and its bank of questions (the admin is the only author), and the
 * badges freelancers earned (to take one back). Same permission group as the skill catalog (`freelance-catalogs`).
 */
class FreelanceSkillTestController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelanceSkillTests $tests) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-catalogs', [
            ['view', ['index', 'show', 'badges']],
            ['create', ['storeQuestion']],
            ['update', ['update', 'updateQuestion', 'revoke']],
            ['delete', ['destroyQuestion']],
        ]);
    }

    /** GET freelance-skill-tests[?search=&only=with_test|on&per_page=] — the active skills with their test and bank at a glance. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:80'], 'only' => ['nullable', 'in:with_test,on'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $term = isset($f['search']) ? \Modules\Freelance\Services\Catalog\FreelanceSkills::normalize($f['search']) : '';

        $page = FreelanceSkill::query()->with('translations')->where('status', FreelanceSkill::ACTIVE)
            ->when($term !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('normalized', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when(($f['only'] ?? null) === 'with_test', fn ($q) => $q->whereExists(fn ($x) => $x->from('freelance_skill_questions as q')->whereColumn('q.freelance_skill_id', 'freelance_skills.id')))
            ->when(($f['only'] ?? null) === 'on', fn ($q) => $q->whereExists(fn ($x) => $x->from('freelance_skill_tests as t')->whereColumn('t.freelance_skill_id', 'freelance_skills.id')->where('t.status', true)))
            ->orderByDesc('usage_count')->orderBy('id')->paginate((int) ($f['per_page'] ?? 15));

        $ids = $page->getCollection()->pluck('id');
        $tests = FreelanceSkillTest::query()->whereIn('freelance_skill_id', $ids)->get()->keyBy('freelance_skill_id');
        $questions = FreelanceSkillQuestion::query()->whereIn('freelance_skill_id', $ids)->selectRaw('freelance_skill_id, count(*) as total, sum(status) as active')->groupBy('freelance_skill_id')->get()->keyBy('freelance_skill_id');
        $badges = FreelancerSkillBadge::query()->whereIn('freelance_skill_id', $ids)->whereNull('revoked_at')->selectRaw('freelance_skill_id, count(*) as n')->groupBy('freelance_skill_id')->pluck('n', 'freelance_skill_id');

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceSkill $s) => [
            'id' => $s->id,
            'name' => $s->translatedName(),
            'usage_count' => (int) $s->usage_count,
            'test' => FreelanceSkillTests::settings($tests->get($s->id)),
            'questions_total' => (int) ($questions->get($s->id)->total ?? 0),
            'questions_active' => (int) ($questions->get($s->id)->active ?? 0),
            'badges' => (int) ($badges[$s->id] ?? 0),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET freelance-skill-tests/{skill} — the settings and every question with its right answer (admin only). */
    public function show(FreelanceSkill $freelanceSkill): JsonResponse
    {
        return ApiResponse::success($this->presentSkill($freelanceSkill), __('api.retrieved'));
    }

    /** PUT freelance-skill-tests/{skill} — how the test is set; switching it on needs enough active questions. */
    public function update(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $d = $request->validate([
            'status' => ['sometimes', 'boolean'],
            'questions_count' => ['sometimes', 'integer', 'min:'.FreelanceSkillTests::MIN_QUESTIONS, 'max:50'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'pass_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'retake_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            // FS-602: the score that makes an intermediate, and the practical challenge for advanced / expert.
            'intermediate_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'challenge_enabled' => ['sometimes', 'boolean'],
            'challenge_brief' => ['sometimes', 'nullable', 'array'],
            'challenge_brief.ar' => ['nullable', 'string', 'max:3000'],
            'challenge_brief.en' => ['nullable', 'string', 'max:3000'],
        ]);
        $current = FreelanceSkillTests::settings(FreelanceSkillTest::query()->where('freelance_skill_id', $freelanceSkill->id)->first());
        $next = array_merge($current, $d);
        if ($next['intermediate_percent'] < $next['pass_percent']) {
            throw ValidationException::withMessages(['intermediate_percent' => [__('validation.gte.numeric', ['attribute' => 'intermediate_percent', 'value' => $next['pass_percent']])]]);
        }
        $brief = (array) ($next['challenge_brief'] ?? []);
        if ($next['challenge_enabled'] && trim((string) ($brief['ar'] ?? '')) === '' && trim((string) ($brief['en'] ?? '')) === '') {
            throw ValidationException::withMessages(['challenge_brief' => [__('validation.required', ['attribute' => 'challenge_brief'])]]);
        }
        $active = FreelanceSkillQuestion::query()->where('freelance_skill_id', $freelanceSkill->id)->where('status', true)->count();
        if ($next['status'] && $active < FreelanceSkillTests::MIN_QUESTIONS) {
            throw ValidationException::withMessages(['status' => [__('freelance.errors.skill_test_few')]]);
        }
        FreelanceSkillTest::query()->updateOrCreate(['freelance_skill_id' => $freelanceSkill->id], $next);

        return ApiResponse::success($this->presentSkill($freelanceSkill), __('api.updated'));
    }

    public function storeQuestion(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $q = $freelanceSkill->hasMany(FreelanceSkillQuestion::class)->create($this->validatedQuestion($request) + ['sort_order' => (int) FreelanceSkillQuestion::query()->where('freelance_skill_id', $freelanceSkill->id)->max('sort_order') + 1]);

        return ApiResponse::created($this->presentQuestion($q), __('api.created'));
    }

    public function updateQuestion(Request $request, FreelanceSkillQuestion $question): JsonResponse
    {
        $question->update($this->validatedQuestion($request));
        $this->switchOffIfTooFew($question->freelance_skill_id);

        return ApiResponse::success($this->presentQuestion($question->refresh()), __('api.updated'));
    }

    public function destroyQuestion(FreelanceSkillQuestion $question): JsonResponse
    {
        $skillId = $question->freelance_skill_id;
        $question->delete();
        $this->switchOffIfTooFew($skillId);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET freelance-skill-badges[?skill_id=&state=valid|revoked|all&per_page=] */
    public function badges(Request $request): JsonResponse
    {
        $f = $request->validate(['skill_id' => ['nullable', 'integer'], 'state' => ['nullable', 'in:valid,revoked,all'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = FreelancerSkillBadge::query()->with(['freelancer', 'skill.translations'])
            ->when(isset($f['skill_id']), fn ($q) => $q->where('freelance_skill_id', $f['skill_id']))
            ->when(($f['state'] ?? 'valid') === 'valid', fn ($q) => $q->whereNull('revoked_at'))
            ->when(($f['state'] ?? null) === 'revoked', fn ($q) => $q->whereNotNull('revoked_at'))
            ->latest('earned_at')->paginate((int) ($f['per_page'] ?? 15));

        return ApiResponse::success($page->getCollection()->map(fn (FreelancerSkillBadge $b) => [
            'id' => $b->id,
            'freelancer' => ['id' => $b->freelancer_id, 'name' => $b->freelancer?->name],
            'skill' => ['id' => $b->freelance_skill_id, 'name' => $b->skill?->translatedName()],
            'score_percent' => $b->score_percent,
            'earned_at' => $b->earned_at?->toIso8601String(),
            'expires_at' => $b->expires_at?->toIso8601String(),
            'is_valid' => $b->isValid(),
            'revoked_at' => $b->revoked_at?->toIso8601String(),
            'revoked_reason' => $b->revoked_reason,
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** POST freelance-skill-badges/{badge}/revoke {reason?} */
    public function revoke(Request $request, FreelancerSkillBadge $badge): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->tests->revoke($badge, $d['reason'] ?? null);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** A test left with fewer active questions than it needs goes off by itself, so a freelancer is never handed half a test. */
    private function switchOffIfTooFew(int $skillId): void
    {
        if (FreelanceSkillQuestion::query()->where('freelance_skill_id', $skillId)->where('status', true)->count() < FreelanceSkillTests::MIN_QUESTIONS) {
            FreelanceSkillTest::query()->where('freelance_skill_id', $skillId)->update(['status' => false]);
        }
    }

    /** @return array<string, mixed> */
    private function validatedQuestion(Request $request): array
    {
        $d = $request->validate([
            'prompt' => ['required', 'array'],
            'prompt.ar' => ['required', 'string', 'min:3', 'max:500'],
            'prompt.en' => ['required', 'string', 'min:3', 'max:500'],
            'options' => ['required', 'array', 'min:2', 'max:6'],
            'options.*.text' => ['required', 'array'],
            'options.*.text.ar' => ['required', 'string', 'max:300'],
            'options.*.text.en' => ['required', 'string', 'max:300'],
            'options.*.correct' => ['required', 'boolean'],
            'status' => ['sometimes', 'boolean'],
        ]);
        if (collect($d['options'])->where('correct', true)->count() !== 1) {
            throw ValidationException::withMessages(['options' => [__('validation.size.array', ['attribute' => 'correct', 'size' => 1])]]);
        }
        $d['prompt'] = ['ar' => $d['prompt']['ar'], 'en' => $d['prompt']['en']];
        $d['options'] = collect($d['options'])->map(fn (array $o) => ['text' => ['ar' => $o['text']['ar'], 'en' => $o['text']['en']], 'correct' => (bool) $o['correct']])->values()->all();

        return $d;
    }

    /** @return array<string, mixed> */
    private function presentSkill(FreelanceSkill $s): array
    {
        $s->loadMissing('translations');

        return [
            'id' => $s->id,
            'name' => $s->translatedName(),
            'test' => FreelanceSkillTests::settings(FreelanceSkillTest::query()->where('freelance_skill_id', $s->id)->first()),
            'min_questions' => FreelanceSkillTests::MIN_QUESTIONS,
            'questions' => FreelanceSkillQuestion::query()->where('freelance_skill_id', $s->id)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($q) => $this->presentQuestion($q))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentQuestion(FreelanceSkillQuestion $q): array
    {
        return ['id' => $q->id, 'prompt' => $q->prompt, 'options' => $q->options, 'status' => (bool) $q->status, 'sort_order' => (int) $q->sort_order];
    }
}
