<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectInvitations;
use Modules\User\Models\User;

/**
 * The client's invitations to a private project (FL-205), at `mobile/v1/freelance/projects/{uuid}/…`:
 * GET invitations, POST invitations {freelancers: [uuid…]}, DELETE invitations/{uuid}, GET invitees[?q=&all=1], POST make-public.
 */
class ClientInvitationController extends Controller
{
    public function __construct(private readonly ProjectInvitations $invitations, private readonly ClientProjects $projects) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($request, $uuid);

        return ApiResponse::success($this->payload($project), __('api.retrieved'));
    }

    public function invitees(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'all' => ['nullable', 'boolean']]);
        $project = $this->project($request, $uuid);

        return ApiResponse::success($this->invitations->invitable($project, $f['q'] ?? null, (bool) ($f['all'] ?? false)), __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['freelancers' => ['required', 'array', 'min:1', 'max:30'], 'freelancers.*' => ['required', 'uuid']]);
        $project = $this->project($request, $uuid);
        $result = $this->invitations->invite($project, $this->me($request), array_values($data['freelancers']));

        return ApiResponse::created($this->payload($project->refresh()) + ['result' => $result], __('freelance.invitations_sent'));
    }

    public function destroy(Request $request, string $uuid, string $invitation): JsonResponse
    {
        $project = $this->project($request, $uuid);
        $this->invitations->withdraw($project, $this->me($request), $invitation);

        return ApiResponse::success($this->payload($project->refresh()), __('api.updated'));
    }

    public function makePublic(Request $request, string $uuid): JsonResponse
    {
        $project = $this->invitations->makePublic($this->project($request, $uuid), $this->me($request));

        return ApiResponse::success($this->projects->present($this->projects->find($this->me($request), $project->uuid)), __('freelance.project_made_public'));
    }

    /** @return array{summary: array<string, mixed>, invitations: list<array<string, mixed>>} */
    private function payload(Project $project): array
    {
        return ['summary' => $this->invitations->summary($project), 'invitations' => $this->invitations->list($project)];
    }

    private function project(Request $request, string $uuid): Project
    {
        return $this->projects->find($this->me($request), $uuid);
    }

    private function me(Request $request): User
    {
        return $request->user('user_api');
    }
}
