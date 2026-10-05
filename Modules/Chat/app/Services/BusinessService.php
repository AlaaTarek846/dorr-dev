<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatBusinessProfile;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatQuickReply;
use Modules\Chat\Support\ParticipantType;

/**
 * Business tools (WhatsApp Business): quick replies typed with "/", opening hours, a welcome
 * message for new customers and an away message when closed.
 *
 * Who gets them is `chat.business_participants` (accounts of those kinds). The automatic replies
 * are sent as the business, only in one-to-one chats, at most:
 *  - away: once per AWAY_EVERY_HOURS in a chat (a customer sending five messages gets one),
 *  - welcome: on a customer's first message, or the first after WELCOME_AFTER_DAYS of silence.
 * When both apply, the away message wins — it says when they'll hear back.
 */
class BusinessService
{
    public const MAX_QUICK_REPLIES = 100;

    public const WELCOME_AFTER_DAYS = 14;

    public const AWAY_EVERY_HOURS = 12;

    public function __construct(
        private readonly MessageService $messages,
        private readonly ChatPrivacy $privacy,
    ) {}

    public static function enabledFor(string $participantType): bool
    {
        return in_array($participantType, (array) config('chat.business_participants', []), true);
    }

    // ---------------------------------------------------------------- profile

    public function profile(Model $me): ChatBusinessProfile
    {
        $this->assertEnabled($me);

        return ChatBusinessProfile::query()->firstOrNew(['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(Model $me, array $data): ChatBusinessProfile
    {
        $profile = $this->profile($me);

        if (array_key_exists('hours', $data)) {
            $data['hours'] = ChatBusinessProfile::normalizeHours($data['hours']);
        }
        $profile->fill($data);

        // Switched on with nothing to say: say so instead of silently sending nothing.
        if ($profile->welcome_enabled && trim((string) $profile->welcome_message) === '') {
            throw new ChatException('business_message_required', 422);
        }
        if ($profile->away_enabled && trim((string) $profile->away_message) === '') {
            throw new ChatException('business_message_required', 422);
        }
        // "Away outside opening hours" means nothing without opening hours.
        if ($profile->away_enabled && $profile->away_mode === ChatBusinessProfile::AWAY_OUTSIDE_HOURS && ! $profile->hasHours()) {
            throw new ChatException('business_hours_required', 422);
        }

        $profile->save();

        return $profile;
    }

    /**
     * What a customer sees about this account on the chat screen (hours, open now), or null.
     *
     * @return array<string, mixed>|null
     */
    public function publicProfile(Model $owner): ?array
    {
        $type = ParticipantType::aliasFor($owner);

        if (! self::enabledFor($type)) {
            return null;
        }

        return ChatBusinessProfile::query()->where('owner_type', $type)->where('owner_id', $owner->getKey())->first()?->presentPublic();
    }

    // ---------------------------------------------------------------- quick replies

    /**
     * @return list<array<string, mixed>>
     */
    public function quickReplies(Model $me): array
    {
        $this->assertEnabled($me);

        return ChatQuickReply::query()->ownedBy($me)->orderBy('shortcut')->get()->map->present()->all();
    }

    public function addQuickReply(Model $me, string $shortcut, string $body): ChatQuickReply
    {
        $this->assertEnabled($me);

        return DB::transaction(function () use ($me, $shortcut, $body) {
            if (ChatQuickReply::query()->ownedBy($me)->lockForUpdate()->count() >= self::MAX_QUICK_REPLIES) {
                throw new ChatException('too_many_quick_replies', 422, ['max' => self::MAX_QUICK_REPLIES]);
            }
            $shortcut = self::shortcut($shortcut);
            $this->assertShortcutFree($me, $shortcut);

            return ChatQuickReply::query()->create([
                'owner_type' => ParticipantType::aliasFor($me),
                'owner_id' => $me->getKey(),
                'shortcut' => $shortcut,
                'body' => trim($body),
            ]);
        });
    }

    /**
     * @param  array{shortcut?: string, body?: string}  $data
     */
    public function updateQuickReply(Model $me, int $id, array $data): ChatQuickReply
    {
        $reply = $this->ownQuickReply($me, $id);

        if (isset($data['shortcut'])) {
            $shortcut = self::shortcut($data['shortcut']);
            if ($shortcut !== $reply->shortcut) {
                $this->assertShortcutFree($me, $shortcut);
            }
            $reply->shortcut = $shortcut;
        }
        if (isset($data['body'])) {
            $reply->body = trim($data['body']);
        }
        $reply->save();

        return $reply;
    }

    public function removeQuickReply(Model $me, int $id): void
    {
        $this->ownQuickReply($me, $id)->delete();
    }

    // ---------------------------------------------------------------- automatic replies

    /**
     * Someone just wrote to a business in a one-to-one chat: send its away or welcome message if due.
     */
    public function answer(ChatConversation $conversation, ChatMessage $incoming, ChatParticipant $customer): void
    {
        $business = $conversation->activeParticipants()->where('id', '!=', $customer->id)->first();

        if ($business === null || ! self::enabledFor($business->participant_type)) {
            return;
        }

        $profile = ChatBusinessProfile::query()
            ->where('owner_type', $business->participant_type)->where('owner_id', $business->participant_id)->first();

        if ($profile === null || (! $profile->welcome_enabled && ! $profile->away_enabled)) {
            return;
        }

        // Blocked either way: the chat is closed, nothing goes out.
        $owner = $business->participant();
        $sender = $customer->participant();
        if ($owner === null || $sender === null || $this->privacy->isBlockedBetween($owner, $sender)) {
            return;
        }

        if ($profile->isAwayAt(now())) {
            $alreadyTold = ChatMessage::query()->where('conversation_id', $conversation->id)
                ->where('sender_type', $business->participant_type)->where('sender_id', $business->participant_id)
                ->where('meta->auto_reply', 'away')
                ->where('created_at', '>=', now()->subHours(self::AWAY_EVERY_HOURS))
                ->exists();

            if (! $alreadyTold) {
                $this->messages->autoReply($conversation, $business, trim((string) $profile->away_message), 'away');
            }

            return;
        }

        if ($profile->welcome_enabled && trim((string) $profile->welcome_message) !== '') {
            // The last real message before this one (system lines don't count).
            $previous = ChatMessage::query()->where('conversation_id', $conversation->id)
                ->where('id', '<', $incoming->id)->whereNotNull('sender_type')
                ->latest('id')->value('created_at');

            if ($previous === null || \Illuminate\Support\Carbon::parse($previous)->lt(now()->subDays(self::WELCOME_AFTER_DAYS))) {
                $this->messages->autoReply($conversation, $business, trim((string) $profile->welcome_message), 'welcome');
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function assertEnabled(Model $me): void
    {
        if (! self::enabledFor(ParticipantType::aliasFor($me))) {
            throw new ChatException('business_unavailable', 403);
        }
    }

    /**
     * Shortcuts are matched as typed after "/", case-insensitively: stored lower-case, no "/".
     */
    private static function shortcut(string $shortcut): string
    {
        return mb_strtolower(ltrim(trim($shortcut), '/'));
    }

    private function assertShortcutFree(Model $me, string $shortcut): void
    {
        if (ChatQuickReply::query()->ownedBy($me)->where('shortcut', $shortcut)->exists()) {
            throw new ChatException('quick_reply_taken', 422);
        }
    }

    private function ownQuickReply(Model $me, int $id): ChatQuickReply
    {
        $this->assertEnabled($me);

        return ChatQuickReply::query()->ownedBy($me)->whereKey($id)->first()
            ?? throw new ChatException('quick_reply_not_found', 404);
    }
}
