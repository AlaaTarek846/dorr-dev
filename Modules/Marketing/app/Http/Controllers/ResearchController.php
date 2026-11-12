<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketing\Http\Resources\JobResource;
use Modules\Marketing\Http\Resources\ResearchRunResource;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingResearchRun;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\Research\ResearchService;

class ResearchController extends Controller
{
    public function __construct(private readonly ResearchService $research, private readonly MarketingAccess $access) {}

    /** GET marketing/projects/{project}/research-runs?kind=&page= */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate(['kind' => ['nullable', Rule::in(MarketingResearchRun::KINDS)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $project->researchRuns()->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->withCount(['sources', 'findings'])->latest('id')->paginate((int) ($data['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, ResearchRunResource::collection($page->items()));
    }

    /** POST marketing/projects/{project}/research-runs  {kind, scope?: {cities, segments, keywords}, max_sources?} — 202 + job */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(MarketingResearchRun::KINDS)],
            'scope' => ['sometimes', 'array'],
            'scope.cities' => ['sometimes', 'array', 'max:5'],
            'scope.cities.*' => ['string', 'max:100'],
            'scope.segments' => ['sometimes', 'array', 'max:5'],
            'scope.segments.*' => ['string', 'max:100'],
            'scope.keywords' => ['sometimes', 'array', 'max:5'],
            'scope.keywords.*' => ['string', 'max:60'],
            'max_sources' => ['sometimes', 'integer', 'min:3', 'max:'.(int) config('marketing.research.max_sources', 15)],
        ]);
        $started = $this->research->start($project, $request->user(), $data['kind'], $data['scope'] ?? [], (int) ($data['max_sources'] ?? config('marketing.research.max_sources', 15)));

        return ApiResponse::success([
            'run' => new ResearchRunResource($started['run']),
            'job' => new JobResource($started['job']),
        ], __('marketing.messages.research_started'), 202);
    }

    /** GET marketing/research-runs/{run} — status, sources and findings. */
    public function show(Request $request, MarketingResearchRun $run)
    {
        $this->access->assert($run->project, $request->user());

        return ApiResponse::success(new ResearchRunResource($run->load(['sources', 'findings'])), __('api.retrieved'));
    }
}
