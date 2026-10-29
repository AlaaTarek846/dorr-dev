<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Chat\FreelanceInterviews;

/**
 * Short interviews (FL-310), one controller for both prefixes, inside a chat room:
 * GET chat/conversations/{uuid}/interviews, POST …/interviews {starts_at, duration_minutes, note}, POST …/interviews/{interview}/{confirm|decline|cancel}.
 */
class FreelanceInterviewController extends Controller
{
    public function __construct(private readonly FreelanceChatService $chat, private readonly FreelanceInterviews $interviews) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $room = $this->chat->forParty($this->me($request), $uuid);

        return ApiResponse::success($this->interviews->list($room), __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $me = $this->me($request);
        $room = $this->chat->forParty($me, $uuid);
        $this->interviews->propose($room, $me, CarbonImmutable::parse($data['starts_at']), (int) ($data['duration_minutes'] ?? 15), $data['note'] ?? null);

        return ApiResponse::created($this->interviews->list($room), __('freelance.interview_proposed'));
    }

    public function respond(Request $request, string $uuid, string $interview, string $action): JsonResponse
    {
        $me = $this->me($request);
        $room = $this->chat->forParty($me, $uuid);
        $this->interviews->respond($room, $me, $interview, $action);

        return ApiResponse::success($this->interviews->list($room), __('api.updated'));
    }

    private function me(Request $request): Model
    {
        return $request->user('freelance_api') ?? $request->user('user_api');
    }
}
