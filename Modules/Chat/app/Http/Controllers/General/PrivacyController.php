<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\PrivacyAudience;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Requests\PrivacySettingsRequest;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\BlockService;
use Modules\Chat\Services\ChatPrivacy;
use Modules\Chat\Services\MessageService;
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
        $settings->update($request->settings());

        return ApiResponse::success($this->present($settings->refresh()), __('api.updated'));
    }

    /**
     * My personal status (spec 90): an emoji and a few words, until a time (or until I clear it),
     * seen by everyone / my contacts / nobody.
     */
    public function setStatus(Request $request)
    {
        $data = $request->validate([
            'emoji' => ['nullable', 'string', 'max:16'],
            'text' => ['nullable', 'string', 'max:100'],
            'until' => ['nullable', 'date', 'after:now'],
            'audience' => ['sometimes', Rule::enum(PrivacyAudience::class)],
        ]);
        if (blank($data['emoji'] ?? null) && blank($data['text'] ?? null)) {
            throw new ChatException('status_empty', 422);
        }

        $settings = $this->privacy->settingsFor($request->user());
        $settings->update([
            'status_emoji' => $data['emoji'] ?? null,
            'status_text' => isset($data['text']) ? trim($data['text']) : null,
            'status_until' => $data['until'] ?? null,
        ] + (isset($data['audience']) ? ['status_audience' => $data['audience']] : []));

        return ApiResponse::success($this->present($settings->refresh()), __('api.updated'));
    }

    public function clearStatus(Request $request)
    {
        $settings = $this->privacy->settingsFor($request->user());
        $settings->update(['status_emoji' => null, 'status_text' => null, 'status_until' => null]);

        return ApiResponse::success($this->present($settings->refresh()), __('api.updated'));
    }

    /**
     * Quick privacy mode (spec 111): every chat notification shows nothing for `minutes`.
     */
    public function privacyModeOn(Request $request)
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:10080']]);
        $settings = $this->privacy->settingsFor($request->user());
        $settings->update([
            'privacy_mode_until' => now()->addMinutes((int) $data['minutes']),
            // Keep the start of a mode that's already on (the summary counts from there).
            'privacy_mode_started_at' => $settings->privacy_mode_until?->isFuture() ? $settings->privacy_mode_started_at : now(),
        ]);

        return ApiResponse::success($this->present($settings->refresh()), __('api.updated'));
    }

    /**
     * Privacy mode off — and what came in meanwhile (spec 113).
     */
    public function privacyModeOff(Request $request, MessageService $messages)
    {
        $settings = $this->privacy->settingsFor($request->user());
        $from = $settings->privacy_mode_started_at ?? now();
        $settings->update(['privacy_mode_until' => null, 'privacy_mode_started_at' => null]);

        return ApiResponse::success(['settings' => $this->present($settings->refresh()), 'summary' => $messages->receivedSince($request->user(), $from)], __('api.updated'));
    }

    /**
     * "While you were private": what came in since `from` (after a timed or scheduled mode ended).
     */
    public function privacySummary(Request $request, MessageService $messages)
    {
        $data = $request->validate(['from' => ['required', 'date', 'before:now']]);

        return ApiResponse::success($messages->receivedSince($request->user(), Carbon::parse($data['from'])), __('api.retrieved'));
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
            'who_can_urgent' => $s->who_can_urgent?->value ?? 'contacts',
            // Privacy mode: on now (until a time or by schedule), and the daily schedule.
            'privacy_mode' => [
                'on' => $s->privacyModeOn(),
                'until' => $s->privacy_mode_until?->isFuture() ? $s->privacy_mode_until->toIso8601String() : null,
                'started_at' => $s->privacy_mode_started_at?->toIso8601String(),
                'schedule' => $s->privacy_schedule,
            ],
            // My status as I set it (also while it's expired, so the app can offer it again).
            'status' => [
                'emoji' => $s->status_emoji,
                'text' => $s->status_text,
                'until' => $s->status_until?->toIso8601String(),
                'audience' => $s->status_audience?->value ?? 'contacts',
                'active' => $s->activeStatus() !== null,
            ],
            'read_receipts' => (bool) ($s->read_receipts ?? true),
            'block_screenshots' => (bool) $s->block_screenshots,
            'notification_privacy' => $s->notification_privacy ?: 'all',
            // Smart quiet: on now, my quiet times, and which chats it covers.
            'quiet' => ['on' => $s->quietOn(), 'schedule' => $s->quiet_schedule, 'scope' => $s->quiet_scope ?: 'all'],
            // Older apps read the on / off switch.
            'notification_preview' => ($s->notification_privacy ?: 'all') === 'all',
        ];
    }
}
