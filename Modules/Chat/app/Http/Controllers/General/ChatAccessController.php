<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\User\Models\User;

/**
 * Who reaches me and how a chat opens:
 *  - a separate PIN for one chat (spec 25), checked here — 5 wrong tries lock it for 5 minutes;
 *  - my @username (spec 81): people find me by it without my phone number (the chat still starts
 *    as a request when they aren't my contact, and my privacy settings still apply).
 */
class ChatAccessController extends Controller
{
    private const MAX_TRIES = 5;

    private const LOCK_MINUTES = 5;

    public function __construct(private readonly ConversationService $conversations) {}

    // ------------------------------------------------------------------ 25 a PIN for one chat

    /** PUT conversations/{c}/lock-pin — `{pin, current_pin?}` (4–8 digits): set or change it; the chat becomes locked. */
    public function setPin(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^\d{4,8}$/'], 'current_pin' => ['nullable', 'string']]);
        $participant = $this->conversations->participantOf($request->user(), $conversation);
        if ($participant->lock_pin_hash !== null) {
            $this->check($participant, (string) ($data['current_pin'] ?? ''));
        }
        $participant->update(['lock_pin_hash' => Hash::make($data['pin']), 'is_locked' => true, 'lock_pin_failures' => 0, 'lock_pin_until' => null]);

        return ApiResponse::success(['has_lock_pin' => true, 'is_locked' => true], __('api.updated'));
    }

    /** DELETE conversations/{c}/lock-pin — `{pin}`: back to the phone's lock (the chat stays locked). */
    public function removePin(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['pin' => ['required', 'string']]);
        $participant = $this->conversations->participantOf($request->user(), $conversation);
        $this->check($participant, $data['pin']);
        $participant->update(['lock_pin_hash' => null, 'lock_pin_failures' => 0, 'lock_pin_until' => null]);

        return ApiResponse::success(['has_lock_pin' => false], __('api.updated'));
    }

    /** POST conversations/{c}/unlock — `{pin}`: right → 200; wrong → 422 with the tries left. */
    public function unlock(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['pin' => ['required', 'string']]);
        $participant = $this->conversations->participantOf($request->user(), $conversation);
        if ($participant->lock_pin_hash === null) {
            return ApiResponse::success(['ok' => true], __('api.retrieved'));
        }
        $this->check($participant, $data['pin']);

        return ApiResponse::success(['ok' => true], __('api.retrieved'));
    }

    private function check(\Modules\Chat\Models\ChatParticipant $participant, string $pin): void
    {
        if ($participant->lock_pin_until !== null && $participant->lock_pin_until->isFuture()) {
            throw new ChatException('lock_pin_locked', 429, ['minutes' => (int) ceil(now()->diffInSeconds($participant->lock_pin_until) / 60)]);
        }
        if ($participant->lock_pin_hash !== null && Hash::check($pin, $participant->lock_pin_hash)) {
            $participant->update(['lock_pin_failures' => 0, 'lock_pin_until' => null]);

            return;
        }
        $failures = $participant->lock_pin_failures + 1;
        $locked = $failures >= self::MAX_TRIES;
        $participant->update(['lock_pin_failures' => $locked ? 0 : $failures, 'lock_pin_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null]);
        if ($locked) {
            throw new ChatException('lock_pin_locked', 429, ['minutes' => self::LOCK_MINUTES]);
        }

        throw new ChatException('lock_pin_wrong', 422, ['left' => self::MAX_TRIES - $failures], ['tries_left' => self::MAX_TRIES - $failures]);
    }

    // ------------------------------------------------------------------ 81 @username

    /** GET username — mine. */
    public function username(Request $request)
    {
        return ApiResponse::success(['username' => $request->user()->chat_username ?? null], __('api.retrieved'));
    }

    /** PUT username — `{username|null}`: letters, digits and _ (3–32), unique among people and channels. */
    public function setUsername(Request $request)
    {
        $data = $request->validate(['username' => ['present', 'nullable', 'string', 'max:40']]);
        $me = $request->user();
        if (! $me instanceof User) {
            throw new ChatException('username_unavailable', 422);
        }
        $name = $data['username'] === null ? null : strtolower(ltrim(trim($data['username']), '@'));
        if ($name === '') {
            $name = null;
        }
        if ($name !== null) {
            if (! preg_match('/^[a-z][a-z0-9_]{2,31}$/', $name)) {
                throw new ChatException('username_invalid', 422);
            }
            $taken = User::query()->where('chat_username', $name)->whereKeyNot($me->id)->exists() || ChatGroup::query()->where('handle', $name)->exists();
            if ($taken) {
                throw new ChatException('username_taken', 422);
            }
        }
        $me->forceFill(['chat_username' => $name])->save();

        return ApiResponse::success(['username' => $name], __('api.updated'));
    }

    /** GET users/by-username?u= — someone's profile by their @username, to start a chat. */
    public function findByUsername(Request $request, ParticipantDirectory $directory)
    {
        $data = $request->validate(['u' => ['required', 'string', 'max:40']]);
        $name = strtolower(ltrim(trim($data['u']), '@'));
        $user = User::query()->where('chat_username', $name)->where('status', 'active')->first() ?? throw ChatException::userNotFound();
        $profile = $directory->profile($request->user(), 'user', $user->id);
        // Found by name, not by number: their phone stays hidden.
        $profile['phone'] = null;

        return ApiResponse::success($profile, __('api.retrieved'));
    }
}
