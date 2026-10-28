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
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSkills;

/**
 * Admin → Freelance → Skills (FL-102): the catalog, the freelancers' suggestions waiting for a decision, and merging
 * duplicates. Same permission group as the specialties (`freelance-catalogs`).
 */
class FreelanceSkillController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelanceSkills $skills) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-catalogs', [
            ['view', ['index', 'show', 'options']],
            ['create', ['store']],
            ['update', ['update', 'status', 'approve', 'reject', 'merge']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    /** GET freelance-skills[?status=&specialty_id=&search=&per_page=&page=] with the count of each status in `pagination.counts`. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(FreelanceSkill::STATUSES)],
            'specialty_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = isset($f['search']) ? FreelanceSkills::normalize($f['search']) : '';

        $page = FreelanceSkill::query()->with(['translations', 'specialties.translations', 'mergedInto.translations'])
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['specialty_id']), fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('freelance_specialties.id', $f['specialty_id'])))
            ->when($term !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('normalized', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $counts = FreelanceSkill::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $meta = ApiPaginator::meta($page) + ['counts' => array_merge(array_fill_keys(FreelanceSkill::STATUSES, 0), $counts->map(fn ($n) => (int) $n)->all())];

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceSkill $s) => $this->present($s))->values(), __('api.retrieved'), 200, $meta);
    }

    public function show(FreelanceSkill $freelanceSkill): JsonResponse
    {
        return ApiResponse::success($this->present($freelanceSkill->load(['translations', 'specialties.translations', 'mergedInto.translations'])), __('api.retrieved'));
    }

    /** Selects of the screen: every specialty (a main one with its sub-specialties) to tie a skill to. */
    public function options(): JsonResponse
    {
        $specialties = FreelanceSpecialty::query()->with(['translations', 'parent.translations'])->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName(), 'parent' => $s->parent?->translatedName(), 'parent_id' => $s->parent_id])->values();

        return ApiResponse::success(['specialties' => $specialties, 'required_locales' => FreelanceSkills::REQUIRED_LOCALES], __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $skill = $this->skills->create($this->validated($request, true));

        return ApiResponse::created($this->present($skill), __('api.created'));
    }

    public function update(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        return ApiResponse::success($this->present($this->skills->update($freelanceSkill, $this->validated($request, false))), __('api.updated'));
    }

    public function status(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);

        return ApiResponse::success($this->present($this->skills->setActive($freelanceSkill, (bool) $data['status'])->load(['translations', 'specialties.translations'])), __('api.updated'));
    }

    /** POST {id}/approve {translations?[], specialty_ids?[]} — takes a suggestion into the catalog (both languages needed). */
    public function approve(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $data = $request->validate([
            'translations' => ['nullable', 'array'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:80'],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => ['integer', 'exists:freelance_specialties,id'],
        ]);

        return ApiResponse::success($this->present($this->skills->approve($freelanceSkill, $data['translations'] ?? [], $data['specialty_ids'] ?? null)), __('api.updated'));
    }

    public function reject(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::success($this->present($this->skills->reject($freelanceSkill, $data['reason'] ?? null)->load(['translations', 'specialties.translations'])), __('api.updated'));
    }

    /** POST {id}/merge {target_id} — this skill becomes an alias of the target. */
    public function merge(Request $request, FreelanceSkill $freelanceSkill): JsonResponse
    {
        $data = $request->validate(['target_id' => ['required', 'integer', 'exists:freelance_skills,id']]);
        $target = $this->skills->merge($freelanceSkill, FreelanceSkill::query()->findOrFail($data['target_id']));

        return ApiResponse::success($this->present($target), __('api.updated'));
    }

    public function destroy(FreelanceSkill $freelanceSkill): JsonResponse
    {
        $this->skills->delete($freelanceSkill);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function deleteMultiple(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        return ApiResponse::success($this->skills->deleteMany($data['ids']), __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'translations' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:80'],
            'specialty_ids' => ['sometimes', 'array'],
            'specialty_ids.*' => ['integer', 'exists:freelance_specialties,id'],
            'status' => ['sometimes', Rule::in([FreelanceSkill::ACTIVE, FreelanceSkill::INACTIVE])],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FreelanceSkill $s): array
    {
        return [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->translatedName(),
            'status' => $s->status,
            'is_active' => $s->status === FreelanceSkill::ACTIVE,
            'usage_count' => (int) $s->usage_count,
            'suggested_by' => $s->suggested_by_type === null ? null : ['type' => $s->suggested_by_type, 'id' => $s->suggested_by_id],
            'reject_reason' => $s->reject_reason,
            'merged_into' => $s->mergedInto === null ? null : ['id' => $s->mergedInto->id, 'name' => $s->mergedInto->translatedName()],
            'specialties' => $s->specialties->map(fn (FreelanceSpecialty $sp) => ['id' => $sp->id, 'name' => $sp->translatedName()])->values()->all(),
            'translations' => $s->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values()->all(),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
