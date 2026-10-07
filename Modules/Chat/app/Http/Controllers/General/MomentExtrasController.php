<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCollabCard;
use Modules\Chat\Models\ChatMomentCapsule;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\CapsuleService;
use Modules\Chat\Services\CollabCardService;
use Modules\Chat\Services\MessageService;

/**
 * DORR Moments together and kept: group cards (spec 163) and capsules (166).
 */
class MomentExtrasController extends Controller
{
    public function __construct(
        private readonly CollabCardService $collab,
        private readonly CapsuleService $capsules,
    ) {}

    // ------------------------------------------------------------------ group cards

    /** GET collab-cards — the ones I organise or was invited to sign. */
    public function cards(Request $request)
    {
        $me = $request->user();
        $rows = ChatCollabCard::query()->visibleTo($me)->whereIn('status', ['collecting', 'sent'])->latest('id')->limit(100)->get()
            ->map(fn ($c) => $this->collab->present($me, $c));

        return ApiResponse::success($rows->values(), __('api.retrieved'));
    }

    public function createCard(Request $request)
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'moment_id' => ['nullable', 'integer'],
            'personal_kind' => ['nullable', Rule::in(ChatPersonalMoment::KINDS)],
            'title' => ['nullable', 'string', 'max:120'],
            'deadline_at' => ['nullable', 'date', 'after:now'],
            'members' => ['nullable', 'array', 'max:50'],
            'members.*' => ['integer'],
        ]);
        $me = $request->user();

        return ApiResponse::created($this->collab->present($me, $this->collab->create($me, $data)), __('api.created'));
    }

    public function card(Request $request, ChatCollabCard $card)
    {
        $me = $request->user();
        $this->visible($me, $card);

        return ApiResponse::success($this->collab->present($me, $card), __('api.retrieved'));
    }

    /** POST collab-cards/{id}/members — `{members[]}` (organiser). */
    public function invite(Request $request, ChatCollabCard $card)
    {
        $data = $request->validate(['members' => ['required', 'array', 'max:50'], 'members.*' => ['integer']]);
        $me = $request->user();

        return ApiResponse::success($this->collab->present($me, $this->collab->invite($me, $card, $data['members'])), __('api.updated'));
    }

    public function removeMember(Request $request, ChatCollabCard $card, int $user)
    {
        $me = $request->user();

        return ApiResponse::success($this->collab->present($me, $this->collab->removeMember($me, $card, $user)), __('api.updated'));
    }

    /** POST collab-cards/{id}/contribution (multipart) — `{text?, voice?, photo?, clear_files?}`: my part. */
    public function contribute(Request $request, ChatCollabCard $card)
    {
        $max = ChatSetting::current()->max_file_size_mb * 1024;
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:1000'],
            'voice' => ['nullable', 'file', 'max:'.$max, 'mimes:m4a,aac,mp3,ogg,oga,opus,wav,amr,mp4,3gp,webm'],
            'photo' => ['nullable', 'image', 'max:'.$max],
            'clear_files' => ['nullable', 'boolean'],
        ]);
        $me = $request->user();
        $files = array_values(array_filter([$request->file('voice'), $request->file('photo')]));
        $this->collab->contribute($me, $card, $data['text'] ?? null, $files, (bool) ($data['clear_files'] ?? false));

        return ApiResponse::success($this->collab->present($me, $card->refresh()), __('api.updated'));
    }

    public function sendCard(Request $request, ChatCollabCard $card)
    {
        $me = $request->user();
        $message = $this->collab->send($me, $card);

        return ApiResponse::created(app(MessageService::class)->presentOne($me, $message), __('api.created'));
    }

    public function cancelCard(Request $request, ChatCollabCard $card)
    {
        $this->collab->cancel($request->user(), $card);

        return ApiResponse::success(null, __('api.deleted'));
    }

    // ------------------------------------------------------------------ capsules

    public function capsules(Request $request)
    {
        $rows = ChatMomentCapsule::query()->ownedBy($request->user())->withCount('items')->latest('updated_at')->get()
            ->map(fn ($c) => $this->capsules->present($c));

        return ApiResponse::success($rows->values(), __('api.retrieved'));
    }

    public function createCapsule(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'emoji' => ['nullable', 'string', 'max:16'], 'moment_id' => ['nullable', 'integer', 'exists:chat_moments,id']]);

        return ApiResponse::created($this->capsules->present($this->capsules->create($request->user(), $data), true), __('api.created'));
    }

    public function capsule(Request $request, ChatMomentCapsule $capsule)
    {
        $this->capsules->assertMine($request->user(), $capsule);

        return ApiResponse::success($this->capsules->present($capsule, true), __('api.retrieved'));
    }

    public function updateCapsule(Request $request, ChatMomentCapsule $capsule)
    {
        $this->capsules->assertMine($request->user(), $capsule);
        $capsule->update($request->validate(['title' => ['sometimes', 'string', 'max:120'], 'emoji' => ['sometimes', 'nullable', 'string', 'max:16']]));

        return ApiResponse::success($this->capsules->present($capsule->refresh(), true), __('api.updated'));
    }

    public function deleteCapsule(Request $request, ChatMomentCapsule $capsule)
    {
        $this->capsules->delete($request->user(), $capsule);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST capsules/{id}/items — `{message_id, note?}`: keep a copy of that message here. */
    public function addItem(Request $request, ChatMomentCapsule $capsule)
    {
        $data = $request->validate(['message_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:300']]);
        $me = $request->user();
        $this->capsules->add($me, $capsule, $data['message_id'], $data['note'] ?? null);

        return ApiResponse::created($this->capsules->present($capsule->refresh(), true), __('api.created'));
    }

    public function removeItem(Request $request, ChatMomentCapsule $capsule, int $item)
    {
        $me = $request->user();
        $this->capsules->remove($me, $capsule, $item);

        return ApiResponse::success($this->capsules->present($capsule->refresh(), true), __('api.updated'));
    }

    private function visible($me, ChatCollabCard $card): void
    {
        if (! $card->isOwnedBy($me) && $card->memberRow($me) === null) {
            throw new ChatException('collab_not_found', 404);
        }
    }
}
