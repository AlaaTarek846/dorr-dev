<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSpecialties;

/** Admin → Freelance → Specialties (FL-101): a specialty and its sub-specialties, each named in every language. */
class FreelanceSpecialtyController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelanceSpecialties $specialties) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-catalogs', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status', 'reorder']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    /** GET freelance-specialties[?parent_id=&status=] — every specialty, each parent with its children right under it. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['parent_id' => ['nullable', 'integer'], 'status' => ['nullable', 'boolean']]);
        $rows = FreelanceSpecialty::query()->with(['translations', 'parent.translations'])->withCount('children')
            ->when(($filters['parent_id'] ?? null) !== null, fn ($q) => $q->where('parent_id', $filters['parent_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (bool) $filters['status']))
            ->orderBy('sort_order')->orderBy('id')->get();

        $parents = $rows->whereNull('parent_id')->values();
        $ordered = $parents->flatMap(fn (FreelanceSpecialty $p) => collect([$p])->concat($rows->where('parent_id', $p->id)->values()))
            ->concat($rows->whereNotNull('parent_id')->filter(fn ($r) => ! $parents->contains('id', $r->parent_id)))->values();

        return ApiResponse::success($ordered->map(fn (FreelanceSpecialty $s) => $this->present($s))->values(), __('api.retrieved'));
    }

    public function show(FreelanceSpecialty $freelanceSpecialty): JsonResponse
    {
        return ApiResponse::success($this->present($freelanceSpecialty->load(['translations', 'parent.translations'])->loadCount('children')), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $specialty = $this->specialties->create($this->validated($request, true));

        return ApiResponse::created($this->present($specialty->load(['parent.translations'])->loadCount('children')), __('api.created'));
    }

    public function update(Request $request, FreelanceSpecialty $freelanceSpecialty): JsonResponse
    {
        $specialty = $this->specialties->update($freelanceSpecialty, $this->validated($request, false));

        return ApiResponse::success($this->present($specialty->load(['parent.translations'])->loadCount('children')), __('api.updated'));
    }

    public function status(Request $request, FreelanceSpecialty $freelanceSpecialty): JsonResponse
    {
        $freelanceSpecialty->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($freelanceSpecialty->load(['translations', 'parent.translations'])->loadCount('children')), __('api.updated'));
    }

    public function destroy(FreelanceSpecialty $freelanceSpecialty): JsonResponse
    {
        $this->specialties->delete($freelanceSpecialty);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST delete-multiple {ids[]} — deletes the ones without sub-specialties; says how many of each. */
    public function deleteMultiple(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        return ApiResponse::success($this->specialties->deleteMany($data['ids']), __('api.deleted'));
    }

    /** PUT reorder {ordered_ids[]} — the ids of one level in their new order. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer', 'exists:freelance_specialties,id']]);
        $this->specialties->reorder($data['ordered_ids']);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:freelance_specialties,id'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => [$required, 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:80'],
            'translations.*.description' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FreelanceSpecialty $s): array
    {
        return [
            'id' => $s->id,
            'parent_id' => $s->parent_id,
            'parent' => $s->parent?->translatedName(),
            'name' => $s->translatedName(),
            'description' => $s->translated('description'),
            'status' => (bool) $s->status,
            'sort_order' => (int) $s->sort_order,
            'children_count' => (int) ($s->children_count ?? 0),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'translations' => $s->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name, 'description' => $t->description])->values()->all(),
        ];
    }
}
