<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatVoiceRoom;
use Modules\Chat\Services\VoiceRoomService;

/** A group's / channel's standing voice room (spec 91). */
class VoiceRoomController extends Controller
{
    public function __construct(private readonly VoiceRoomService $rooms) {}

    /** GET conversations/{c}/voice-room */
    public function show(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->rooms->show($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST conversations/{c}/voice-room/join — `{room, join{url, room, token}}` */
    public function join(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->rooms->join($request->user(), $conversation), __('api.retrieved'));
    }

    public function leave(Request $request, ChatVoiceRoom $room)
    {
        $this->rooms->leave($request->user(), $room);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function ping(Request $request, ChatVoiceRoom $room)
    {
        $this->rooms->ping($request->user(), $room);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** POST voice-rooms/{r}/hand — `{raised}` */
    public function hand(Request $request, ChatVoiceRoom $room)
    {
        $raised = (bool) $request->validate(['raised' => ['required', 'boolean']])['raised'];

        return ApiResponse::success($this->rooms->hand($request->user(), $room, $raised), __('api.updated'));
    }

    /** POST voice-rooms/{r}/members/{participant}/role — `{role: speaker|listener}` (hosts) */
    public function role(Request $request, ChatVoiceRoom $room, int $participant)
    {
        $role = $request->validate(['role' => ['required', Rule::in(['speaker', 'listener'])]])['role'];

        return ApiResponse::success($this->rooms->setRole($request->user(), $room, $participant, $role), __('api.updated'));
    }

    /** POST voice-rooms/{r}/members/{participant}/remove (hosts) */
    public function remove(Request $request, ChatVoiceRoom $room, int $participant)
    {
        return ApiResponse::success($this->rooms->remove($request->user(), $room, $participant), __('api.updated'));
    }

    /** PATCH voice-rooms/{r} — `{speak_policy?: everyone|approved, title?}` (hosts) */
    public function update(Request $request, ChatVoiceRoom $room)
    {
        $data = $request->validate(['speak_policy' => ['sometimes', Rule::in(ChatVoiceRoom::POLICIES)], 'title' => ['sometimes', 'nullable', 'string', 'max:120']]);

        return ApiResponse::success($this->rooms->update($request->user(), $room, $data), __('api.updated'));
    }
}
