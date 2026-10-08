<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Resources\AiSiteProjectResource;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\Sites\AiSiteProjectService;

/** Admin moderation of customer sites: look, take offline, put back, remove. */
class AiSiteAdminProjectController extends Controller
{
    public function __construct(protected AiSiteProjectService $sites) {}

    public function index(Request $request)
    {
        $query = AiSiteProject::query()->with('owner')->latest('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('access_type'), fn ($q) => $q->where('access_type', $request->string('access_type')))
            ->when($request->boolean('disabled'), fn ($q) => $q->whereNotNull('disabled_at'))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'));

        return ApiResponse::paginated($query, AiSiteProjectResource::class);
    }

    public function show(AiSiteProject $project)
    {
        return ApiResponse::success(new AiSiteProjectResource($project->load(['versions', 'purchase', 'owner'])));
    }

    public function disable(AiSiteProject $project)
    {
        $project->forceFill(['disabled_at' => now()])->save();

        return ApiResponse::success(new AiSiteProjectResource($project->load('owner')), __('ai.site_disabled_done'));
    }

    public function enable(AiSiteProject $project)
    {
        $project->forceFill(['disabled_at' => null])->save();

        return ApiResponse::success(new AiSiteProjectResource($project->load('owner')), __('ai.site_enabled_done'));
    }

    public function destroy(AiSiteProject $project)
    {
        $this->sites->delete($project, force: true);

        return ApiResponse::success(null, __('ai.site_deleted'));
    }
}
