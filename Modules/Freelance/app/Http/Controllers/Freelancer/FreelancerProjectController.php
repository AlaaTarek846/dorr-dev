<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\FreelancerProjects;

/**
 * Browsing projects on the freelancer app (FL-206 / FL-207): the published public projects of the freelancer's own country, with
 * filters, one project's page, and the ones they saved. Open to an unverified freelancer too (they can look; applying is gated, FL-301).
 */
class FreelancerProjectController extends Controller
{
    public function __construct(private readonly FreelancerProjects $projects) {}

    /** GET projects[?scope=mine|all&q=&specialty_id=&budget_min_minor=&budget_max_minor=&duration_max_days=&favorites=1&page=&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'specialty_id' => ['nullable', 'integer'],
            'budget_min_minor' => ['nullable', 'integer', 'min:0'],
            'budget_max_minor' => ['nullable', 'integer', 'min:0'],
            'duration_max_days' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'favorites' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'in:mine,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $me = $this->me($request);
        $scope = $this->projects->scopeFor($me, $f['scope'] ?? null, isset($f['specialty_id']) ? (int) $f['specialty_id'] : null);
        $page = $this->projects->browse($me, $f, (int) ($f['per_page'] ?? 15), $scope['ids']);
        $saved = $this->projects->favoriteIds($me, $page->getCollection());
        $offered = $this->projects->offeredIds($me, $page->getCollection());

        return ApiResponse::success(
            $page->getCollection()->map(fn ($p) => $this->projects->present($p, isset($saved[$p->id]), false) + ['offered' => isset($offered[$p->id])])->values(),
            __('api.retrieved'), 200, ApiPaginator::meta($page) + ['scope' => $scope['scope'], 'has_specialties' => $scope['has_specialties']],
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $project = $this->projects->find($me, $uuid);

        return ApiResponse::success($this->projects->present($project, isset($this->projects->favoriteIds($me, [$project])[$project->id]), true) + ['offered' => isset($this->projects->offeredIds($me, [$project])[$project->id])], __('api.retrieved'));
    }

    public function favorite(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->projects->favorite($me, $this->projects->find($me, $uuid));

        return $this->show($request, $uuid);
    }

    public function unfavorite(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->projects->unfavorite($me, $this->projects->find($me, $uuid));

        return $this->show($request, $uuid);
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
