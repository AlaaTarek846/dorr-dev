<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use App\Support\Api\ApiPaginator;

/**
 * What the apps read of the catalogs (FL-101): the active specialty tree in the language of the request —
 * for the freelancer at `freelance/v1/specialties`, for the client at `mobile/v1/freelance/specialties`.
 * Behind the `freelance` switch of the caller's country. An inactive specialty hides itself and its
 * sub-specialties.
 */
class FreelanceCatalogController extends Controller
{
    public function __construct(private readonly FreelanceSkills $skills) {}

    /** GET skills[?specialty_id=&q=&per_page=&page=] — the active skills (and the freelancer's own suggestions waiting), in the request's language. */
    public function skills(Request $request): JsonResponse
    {
        $f = $request->validate(['specialty_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $viewer = $request->user('freelance_api') ?? $request->user('user_api');
        $page = $this->skills->catalog($viewer, $f, (int) ($f['per_page'] ?? 30));

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceSkill $s) => $this->presentSkill($s))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** POST skills/suggest {name, locale?, specialty_id?} — an existing skill comes back as it is; a new one waits for the admin. */
    public function suggestSkill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'locale' => ['nullable', Rule::in(FreelanceSkills::REQUIRED_LOCALES)],
            'specialty_id' => ['nullable', 'integer'],
        ]);
        $result = $this->skills->suggest($request->user('freelance_api'), $data['name'], $data['locale'] ?? app()->getLocale(), $data['specialty_id'] ?? null);

        return ApiResponse::success($this->presentSkill($result['skill']) + ['created' => $result['created']], __($result['created'] ? 'api.created' : 'api.retrieved'), $result['created'] ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function presentSkill(FreelanceSkill $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->translatedName(),
            'pending' => $s->status === FreelanceSkill::PENDING,
            'specialty_ids' => $s->relationLoaded('specialties') ? $s->specialties->pluck('id')->all() : [],
        ];
    }

    public function specialties(): JsonResponse
    {
        $roots = FreelanceSpecialty::query()
            ->where('status', true)->whereNull('parent_id')
            ->with(['translations', 'children' => fn ($q) => $q->where('status', true)->with('translations')])
            ->orderBy('sort_order')->orderBy('id')->get();

        $tree = $roots->map(fn (FreelanceSpecialty $s) => $this->present($s) + [
            'children' => $s->children->map(fn (FreelanceSpecialty $c) => $this->present($c))->values(),
        ])->values();

        return ApiResponse::success($tree, __('api.retrieved'), 200, ['version' => $this->version()]);
    }

    /** @return array<string, mixed> */
    private function present(FreelanceSpecialty $s): array
    {
        return ['id' => $s->id, 'name' => $s->translatedName(), 'description' => $s->translated('description'), 'sort_order' => $s->sort_order];
    }

    /** Changes whenever the catalog does, so an app can keep its copy until then. */
    private function version(): string
    {
        return md5((string) FreelanceSpecialty::query()->max('updated_at').'|'.FreelanceSpecialty::query()->count());
    }
}
