<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Requests\PrivacySettingsRequest;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\BlockService;
use Modules\Chat\Services\ChatPrivacy;
use Modules\Chat\Services\PresenceService;
use Modules\Chat\Support\ParticipantType;

/**
 * My privacy settings, blocked people, and my presence (online / last seen).
 */
class PrivacyController extends Controller
{
    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly BlockService $blocks,
        private readonly PresenceService $presence,
    ) {}

    public function show(Request $request)
    {
        return ApiResponse::success($this->present($this->privacy->settingsFor($request->user())), __('api.retrieved'));
    }

    public function update(PrivacySettingsRequest $request)
    {
        $settings = $this->privacy->settingsFor($request->user());
        $settings->update($request->validated());

        return ApiResponse::success($this->present($settings->refresh()), __('api.updated'));
    }

    public function blocked(Request $request)
    {
        return ApiResponse::success($this->blocks->list($request->user())->values(), __('api.retrieved'));
    }

    public function block(Request $request)
    {
        $this->blocks->block($request->user(), $this->account($request));

        return ApiResponse::success($this->blocks->list($request->user())->values(), __('api.updated'));
    }

    public function unblock(Request $request)
    {
        $this->blocks->unblock($request->user(), $this->account($request));

        return ApiResponse::success($this->blocks->list($request->user())->values(), __('api.updated'));
    }

    /**
     * App in the foreground (and every ~60s while it stays there) → online; background → offline.
     */
    public function presence(Request $request)
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);
        $this->presence->set($request->user(), (bool) $data['online']);

        return ApiResponse::success(null, 'OK');
    }

    private function account(Request $request)
    {
        $data = $request->validate(['participant_type' => ['nullable', 'string'], 'participant_id' => ['required', 'integer']]);
        $alias = $data['participant_type'] ?? 'user';

        if (! array_key_exists($alias, config('chat.participants', []))) {
            throw ChatException::participantDisabled();
        }

        $account = ParticipantType::modelClassFor($alias)::query()->find($data['participant_id']) ?? throw ChatException::userNotFound();

        if (ParticipantType::key($account) === ParticipantType::key($request->user())) {
            throw ChatException::toSelf();
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatPrivacySetting $s): array
    {
        return [
            'last_seen' => $s->last_seen?->value ?? 'everyone',
            'profile_photo' => $s->profile_photo?->value ?? 'everyone',
            'who_can_message' => $s->who_can_message?->value ?? 'everyone',
            'who_can_add_to_groups' => $s->who_can_add_to_groups?->value ?? 'everyone',
            'who_can_call' => $s->who_can_call?->value ?? 'everyone',
            'read_receipts' => (bool) ($s->read_receipts ?? true),
            'block_screenshots' => (bool) $s->block_screenshots,
            'notification_preview' => (bool) ($s->notification_preview ?? true),
        ];
    }
}
