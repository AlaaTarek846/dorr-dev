<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceProjectMember as Member;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Team\ClientTeam;
use Modules\Freelance\Services\Team\ClientTeamAbilities;
use Modules\Freelance\Services\Team\ClientTeamView;
use Modules\User\Models\User;

/**
 * FS-104: a project's team (docs/modules/freelance/PERMISSIONS.md).
 * The owner: GET|POST projects/{uuid}/team, PUT|DELETE projects/{uuid}/team/{member}.
 * The person invited: GET team/invitations, POST team/invitations/{uuid}/accept|decline, GET team/projects, POST team/projects/{uuid}/leave.
 */
class ClientTeamController extends Controller
{
    public function __construct(private readonly ClientTeam $team, private readonly ClientProjects $projects) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);

        return ApiResponse::success($this->page($project), __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ] + $this->roleRules());
        $project = $this->projects->find($this->me($request), $uuid);
        $this->team->invite($project, $this->me($request), $data);

        return ApiResponse::success($this->page($project), __('freelance.team_invited'));
    }

    public function update(Request $request, string $uuid, string $member): JsonResponse
    {
        $data = $request->validate($this->roleRules());
        $project = $this->projects->find($this->me($request), $uuid);
        $this->team->update($project, $this->me($request), $member, $data);

        return ApiResponse::success($this->page($project), __('api.updated'));
    }

    public function destroy(Request $request, string $uuid, string $member): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);
        $this->team->remove($project, $this->me($request), $member);

        return ApiResponse::success($this->page($project), __('api.deleted'));
    }

    public function invitations(Request $request): JsonResponse
    {
        $rows = array_map(fn (Member $m) => $this->team->presentInvitation($m), $this->team->invitations($this->me($request)));

        return ApiResponse::success(['invitations' => $rows], __('api.retrieved'));
    }

    public function respond(Request $request, string $uuid, string $answer): JsonResponse
    {
        $member = $this->team->respond($this->me($request), $uuid, $answer);

        return ApiResponse::success(['status' => $member->status, 'project' => $member->project->uuid],
            __($answer === 'accept' ? 'freelance.team_joined' : 'freelance.team_declined'));
    }

    /** The projects I was brought into, each read as my role allows. */
    public function shared(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->team->shared($this->me($request), (int) ($data['per_page'] ?? 15));
        $rows = $page->getCollection()->map(function (Member $m) {
            $view = ClientTeamView::mask($this->projects->present($m->project), ClientTeamAbilities::allows($m, ClientTeamAbilities::MONEY), ClientTeamAbilities::of($m));

            return $view + ['access' => $this->team->access($m->project, $m)];
        })->values();

        return ApiResponse::fromPaginator($page, $rows, __('api.retrieved'));
    }

    public function leave(Request $request, string $uuid): JsonResponse
    {
        $this->team->leave($this->me($request), $uuid);

        return ApiResponse::success(null, __('freelance.team_left'));
    }

    /** @return array<string, mixed> */
    private function page(\Modules\Freelance\Models\FreelanceProject $project): array
    {
        return [
            'members' => array_map(fn (Member $m) => $this->team->present($m), $this->team->members($project)),
            'max' => ClientTeam::maxMembers(),
            'roles' => array_keys(ClientTeamAbilities::ROLES),
            'max_delegate_days' => ClientTeam::MAX_DELEGATE_DAYS,
            'currency' => $project->currency_code,
        ];
    }

    /** @return array<string, list<mixed>> */
    private function roleRules(): array
    {
        return [
            'role' => ['required', Rule::in(Member::ROLES)],
            'max_amount_minor' => ['nullable', 'required_if:role,delegate', 'integer', 'min:1', 'max:100000000000'],
            'days' => ['nullable', 'required_if:role,delegate', 'integer', 'min:1', 'max:'.ClientTeam::MAX_DELEGATE_DAYS],
        ];
    }

    private function me(Request $request): User
    {
        return $request->user('user_api');
    }
}
