<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceInternalMessage;
use Modules\Freelance\Models\FreelanceInternalThread;
use Modules\Freelance\Models\FreelanceTeamMember;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamChat;

/**
 * A team's internal chat for its people (FS-150d), under `freelance/v1/teams/...` behind the `teams` switch. The client never reaches it.
 * See TeamChat for the rules.
 */
class TeamChatController extends Controller
{
    public function __construct(
        private readonly TeamChat $chat,
        private readonly FreelancerTeams $teams,
    ) {}

    /** GET teams - the teams I am in now, with my role (the way into each team's chat). */
    public function teams(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $rows = $this->teams->memberships($me);
        // FS-209: what a team card shows (logo, badge, size) in one extra query, never one per team.
        $sizes = FreelanceTeamMember::query()->whereIn('team_id', $rows->pluck('team_id'))->where('status', FreelanceTeamMember::ACTIVE)
            ->selectRaw('team_id, count(*) as people')->groupBy('team_id')->pluck('people', 'team_id');

        return ApiResponse::success(['teams' => $rows->map(fn (FreelanceTeamMember $m) => [
            'uuid' => $m->team->uuid,
            'name' => $m->team->name,
            'status' => $m->team->status,
            'role' => $m->role,
            'joined_at' => $m->joined_at?->toIso8601String(),
            'logo_url' => $m->team->logoUrl(),
            'verified' => $m->team->verified_at !== null,
            'members_count' => (int) ($sizes[$m->team_id] ?? 0),
            'accepting_projects' => (bool) $m->team->accepting_projects,
            'visibility' => $m->team->visibility,
        ])->values()], __('api.retrieved'));
    }

    /** GET teams/{team}/chat/threads - the general thread first (made the first time), then each project's. */
    public function threads(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->chat->team($me, $team);

        return ApiResponse::success([
            'threads' => $this->chat->threads($t, $me)->map(fn (FreelanceInternalThread $th) => $this->chat->presentThread($th, $t, $me))->values(),
            'can_write' => $this->chat->canWrite($t),
            'can_open_project' => $this->chat->canWrite($t) && $this->teams->isOwner($t, $me),
            // Transparency (TEAMS-ERD §4): said on the screen of every thread.
            'notice' => __('freelance.internal_chat_notice'),
        ], __('api.retrieved'));
    }

    /** POST teams/{team}/chat/threads {project: uuid} - an Owner opens the thread about a project the team works on (or gets it). */
    public function openProject(Request $request, string $team): JsonResponse
    {
        $data = $request->validate(['project' => ['required', 'uuid']]);
        $me = $this->me($request);
        $t = $this->chat->team($me, $team);

        return ApiResponse::success(['thread' => $this->chat->presentThread($this->chat->openProject($t, $me, $data['project']), $t, $me)], __('api.retrieved'));
    }

    /** GET teams/{team}/chat/threads/{thread}/messages[?before=id&limit=1..100] - newest first. */
    public function messages(Request $request, string $team, string $thread): JsonResponse
    {
        $f = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $me = $this->me($request);
        $t = $this->chat->team($me, $team);
        $th = $this->chat->thread($t, $thread, $me);
        $rows = $this->chat->messages($th, (int) ($f['limit'] ?? 30), isset($f['before']) ? (int) $f['before'] : null);

        return ApiResponse::success([
            'thread' => $this->chat->presentThread($th, $t, $me),
            'messages' => $rows->map(fn (FreelanceInternalMessage $m) => $this->chat->present($m, $me))->values(),
            'next_before' => $rows->count() === (int) ($f['limit'] ?? 30) ? $rows->last()?->id : null,
            'notice' => __('freelance.internal_chat_notice'),
        ], __('api.retrieved'));
    }

    /** POST teams/{team}/chat/threads/{thread}/messages {body (≤2000), client_msg_id?} */
    public function send(Request $request, string $team, string $thread): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.TeamChat::BODY_MAX],
            'client_msg_id' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($request);
        $t = $this->chat->team($me, $team);
        $th = $this->chat->thread($t, $thread, $me);

        return ApiResponse::created(['message' => $this->chat->send($t, $th, $me, $data['body'], $data['client_msg_id'] ?? null)], __('freelance.internal_message_sent'));
    }

    /** POST teams/{team}/chat/threads/{thread}/read */
    public function read(Request $request, string $team, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->chat->team($me, $team);

        return ApiResponse::success(['read' => $this->chat->markRead($t, $this->chat->thread($t, $thread, $me), $me)], __('api.updated'));
    }

    /** DELETE teams/{team}/chat/messages/{message} - its sender, within a few minutes. */
    public function destroy(Request $request, string $team, int $message): JsonResponse
    {
        $me = $this->me($request);
        $this->chat->delete($this->chat->team($me, $team), $me, $message);

        return ApiResponse::success(null, __('freelance.internal_message_deleted'));
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
