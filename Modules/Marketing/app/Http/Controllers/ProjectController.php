<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Marketing\Http\Requests\StoreProjectRequest;
use Modules\Marketing\Http\Resources\JobResource;
use Modules\Marketing\Http\Resources\ProjectResource;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\ProjectService;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects, private readonly MarketingAccess $access) {}

    /** GET marketing/projects?status=&q=&page=&per_page= */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:active,archived'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $this->projects->list($request->user(), $data['status'] ?? null, $data['q'] ?? null, (int) ($data['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, ProjectResource::collection($page->items()));
    }

    /** POST marketing/projects */
    public function store(StoreProjectRequest $request)
    {
        return ApiResponse::created(new ProjectResource($this->projects->create($request->user(), $request->validated())), __('marketing.messages.project_created'));
    }

    /** GET marketing/projects/{project} */
    public function show(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());

        return ApiResponse::success(new ProjectResource($project->load(['profile', 'members'])), __('api.retrieved'));
    }

    /** PATCH marketing/projects/{project} */
    public function update(StoreProjectRequest $request, MarketingProject $project)
    {
        return ApiResponse::success(new ProjectResource($this->projects->update($project, $request->user(), $request->validated())), __('marketing.messages.project_updated'));
    }

    /** POST marketing/projects/{project}/archive  {archived: bool} */
    public function archive(Request $request, MarketingProject $project)
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);

        return ApiResponse::success(new ProjectResource($this->projects->archive($project, $request->user(), (bool) $data['archived'])), __('marketing.messages.project_updated'));
    }

    /** DELETE marketing/projects/{project} */
    public function destroy(Request $request, MarketingProject $project)
    {
        $this->projects->delete($project, $request->user());

        return ApiResponse::success(null, __('marketing.messages.project_deleted'));
    }

    /** GET marketing/projects/{project}/jobs — the latest jobs (catch-up on open / reconnect). */
    public function jobs(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $jobs = MarketingJob::query()->where('project_id', $project->id)->latest('id')->take(20)->get();

        return ApiResponse::success(JobResource::collection($jobs), __('api.retrieved'));
    }

    /** GET marketing/jobs/{job} */
    public function job(Request $request, MarketingJob $job)
    {
        $this->access->assert($job->project, $request->user());

        return ApiResponse::success(new JobResource($job), __('api.retrieved'));
    }
}
