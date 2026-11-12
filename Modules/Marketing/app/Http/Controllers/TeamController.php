<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Http\Resources\MemberResource;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\TeamService;

class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team, private readonly MarketingAccess $access) {}

    /** GET marketing/projects/{project}/members */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $rows = $project->members()->with('member')->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id')->get();

        return ApiResponse::success(MemberResource::collection($rows), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/members  {phone, role} */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'role' => ['required', Rule::in(ProjectRole::assignable())],
        ]);

        return ApiResponse::created(new MemberResource($this->team->add($project, $request->user(), $data['phone'], $data['role'])), __('marketing.messages.member_added'));
    }

    /** PATCH marketing/projects/{project}/members/{member}  {role} */
    public function update(Request $request, MarketingProject $project, MarketingProjectMember $member)
    {
        $data = $request->validate(['role' => ['required', Rule::in(ProjectRole::assignable())]]);

        return ApiResponse::success(new MemberResource($this->team->changeRole($project, $request->user(), $member, $data['role'])), __('marketing.messages.member_updated'));
    }

    /** DELETE marketing/projects/{project}/members/{member} */
    public function destroy(Request $request, MarketingProject $project, MarketingProjectMember $member)
    {
        $this->team->remove($project, $request->user(), $member);

        return ApiResponse::success(null, __('marketing.messages.member_removed'));
    }

    /** POST marketing/projects/{project}/leave */
    public function leave(Request $request, MarketingProject $project)
    {
        $this->team->leave($project, $request->user());

        return ApiResponse::success(null, __('marketing.messages.left'));
    }
}
