<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\GroupParentService;

/**
 * Parents in educational groups (GR-018, 019, 027, decision D8): the student's code, the teacher's
 * queue and settings, the parent's children and meetings. Who may do what is checked in the service.
 */
class GroupParentController extends Controller
{
    public function __construct(private readonly GroupParentService $parents) {}

    // ------------------------------------------------------------------ the student

    /** POST groups/{c}/parents/code — a code for my parent (7 days). */
    public function code(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::created($this->parents->code($request->user(), $conversation), __('api.created'));
    }

    /** GET groups/{c}/parents/mine — who is linked to me. */
    public function mine(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->parents->myParents($request->user(), $conversation), __('api.retrieved'));
    }

    // ------------------------------------------------------------------ the teacher (`parents.link`)

    /** GET groups/{c}/parents?status=pending|approved */
    public function index(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved'])]]);

        return ApiResponse::success($this->parents->forTeacher($request->user(), $conversation, $data['status'] ?? null), __('api.retrieved'));
    }

    /** POST groups/{c}/parents/{link}/decide `{approve}` */
    public function decide(Request $request, ChatConversation $conversation, string $link)
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        return ApiResponse::success($this->parents->decide($request->user(), $conversation, $link, (bool) $data['approve']), __('api.updated'));
    }

    public function destroy(Request $request, ChatConversation $conversation, string $link)
    {
        $this->parents->unlinkByTeacher($request->user(), $conversation, $link);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** PATCH groups/{c}/parents/settings `{messaging}` */
    public function settings(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['messaging' => ['required', 'boolean']]);

        return ApiResponse::success(['messaging' => $this->parents->setMessaging($request->user(), $conversation, (bool) $data['messaging'])], __('api.updated'));
    }

    public function meetings(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->parents->meetingsForTeacher($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST groups/{c}/parent-meetings `{title, starts_at, mode: group|slots, slot_minutes?, slots?, note?}` */
    public function storeMeeting(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date', 'after:now'],
            'mode' => ['required', Rule::in(['group', 'slots'])],
            'slot_minutes' => ['required_if:mode,slots', 'nullable', 'integer', 'between:5,120'],
            'slots' => ['required_if:mode,slots', 'nullable', 'integer', 'between:1,60'],
        ]);

        return ApiResponse::created($this->parents->createMeeting($request->user(), $conversation, $data), __('api.created'));
    }

    public function destroyMeeting(Request $request, ChatConversation $conversation, string $meeting)
    {
        $this->parents->deleteMeeting($request->user(), $conversation, $meeting);

        return ApiResponse::success(null, __('api.deleted'));
    }

    // ------------------------------------------------------------------ the parent

    /** POST parent/link `{code}` — the code my child gave me; a teacher approves. */
    public function redeem(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return ApiResponse::created($this->parents->redeem($request->user(), $data['code']), __('api.created'));
    }

    public function children(Request $request)
    {
        return ApiResponse::success($this->parents->children($request->user()), __('api.retrieved'));
    }

    public function child(Request $request, string $link)
    {
        return ApiResponse::success($this->parents->child($request->user(), $link), __('api.retrieved'));
    }

    public function unlink(Request $request, string $link)
    {
        $this->parents->unlinkByParent($request->user(), $link);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST parent/children/{link}/message `{teacher_participant_id}` — a private chat, if the teachers allow it. */
    public function message(Request $request, string $link)
    {
        $data = $request->validate(['teacher_participant_id' => ['required', 'integer']]);

        return ApiResponse::success($this->parents->messageTeacher($request->user(), $link, (int) $data['teacher_participant_id']), __('api.retrieved'));
    }

    public function childMeetings(Request $request, string $link)
    {
        return ApiResponse::success($this->parents->meetingsForParent($request->user(), $link), __('api.retrieved'));
    }

    /** POST parent/children/{link}/meetings/{m}/book `{slot_at}` */
    public function book(Request $request, string $link, string $meeting)
    {
        $data = $request->validate(['slot_at' => ['required', 'date']]);

        return ApiResponse::success($this->parents->book($request->user(), $link, $meeting, $data['slot_at']), __('api.updated'));
    }

    public function cancelBooking(Request $request, string $link, string $meeting)
    {
        $this->parents->cancelBooking($request->user(), $link, $meeting);

        return ApiResponse::success(null, __('api.deleted'));
    }
}
