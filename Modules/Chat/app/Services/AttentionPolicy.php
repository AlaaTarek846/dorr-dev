<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacySetting;

/**
 * Who a message may interrupt, and how (spec 146, 151, 155):
 *  - the source: contacts · strangers (direct, not in my contacts) · groups · mentions (I'm
 *    mentioned or answered in a group) · channels · money (requests, splits, transfers) · urgent;
 *  - my attention for that source: now · digest (in the next summary) · later (no notification —
 *    it waits in the life inbox; the counter still moves);
 *  - my privacy context (home, work, meeting, travel, private) shifts both, and what the
 *    notification shows — never what anyone may send me (that's the backend's own rules);
 *  - people I chose always get through (they still respect a chat I muted myself).
 * Everything here only changes notifications.
 */
class AttentionPolicy
{
    public const SOURCES = ['contacts', 'strangers', 'groups', 'mentions', 'channels', 'money', 'urgent'];

    public const MODES = ['now', 'digest', 'later'];

    public const CONTEXTS = ['home', 'work', 'meeting', 'travel', 'private'];

    /** Out of the box: everything interrupts, as before. */
    public const DEFAULT = ['contacts' => 'now', 'strangers' => 'now', 'groups' => 'now', 'mentions' => 'now', 'channels' => 'now', 'money' => 'now', 'urgent' => 'now'];

    /**
     * What each context changes (my own changes to a context win). `disclosure`: the most a
     * notification shows (all · name · none); `calls`: everyone · through (only people I chose) · nobody.
     *
     * @return array<string, array{attention: array<string, string>, disclosure: ?string, calls: string}>
     */
    public static function presets(): array
    {
        return [
            'home' => ['attention' => [], 'disclosure' => null, 'calls' => 'everyone'],
            'work' => ['attention' => ['strangers' => 'digest', 'groups' => 'digest', 'channels' => 'later'], 'disclosure' => 'name', 'calls' => 'everyone'],
            'meeting' => ['attention' => ['contacts' => 'digest', 'strangers' => 'later', 'groups' => 'digest', 'mentions' => 'digest', 'channels' => 'later', 'money' => 'digest'], 'disclosure' => 'name', 'calls' => 'through'],
            'travel' => ['attention' => ['groups' => 'digest', 'channels' => 'later'], 'disclosure' => null, 'calls' => 'everyone'],
            'private' => ['attention' => [], 'disclosure' => 'none', 'calls' => 'everyone'],
        ];
    }

    /** @return array{attention: array<string, string>, disclosure: ?string, calls: string} */
    public static function context(ChatPrivacySetting $s, string $key): array
    {
        $preset = self::presets()[$key] ?? self::presets()['home'];
        $mine = (array) data_get($s->contexts, $key, []);

        return [
            'attention' => array_merge($preset['attention'], array_intersect_key((array) ($mine['attention'] ?? []), array_flip(self::SOURCES))),
            'disclosure' => array_key_exists('disclosure', $mine) ? $mine['disclosure'] : $preset['disclosure'],
            'calls' => $mine['calls'] ?? $preset['calls'],
        ];
    }

    /**
     * For each recipient: `mode` (now · digest · later) and the most its notification may show.
     *
     * @param  Collection<int, ChatParticipant>  $recipients
     * @param  array<string, ChatPrivacySetting>  $settings  by owner key
     * @return array<string, array{mode: string, disclosure: ?string, through: bool}>  by owner key
     */
    public function decide(Collection $recipients, ChatConversation $conversation, ChatMessage $message, array $settings): array
    {
        $out = [];
        if ($recipients->isEmpty()) {
            return $out;
        }
        $senderKey = $message->sender_type !== null ? $message->sender_type.':'.$message->sender_id : null;
        $contacts = $this->contactsOfSender($recipients, $message);
        $repliedTo = $message->reply_to_id ? ChatMessage::query()->whereKey($message->reply_to_id)->first(['sender_type', 'sender_id']) : null;
        $mentions = array_map('intval', (array) ($message->mentions ?? []));

        foreach ($recipients as $p) {
            $key = $p->participant_type.':'.$p->participant_id;
            $s = $settings[$key] ?? null;
            if ($s === null) {
                $out[$key] = ['mode' => 'now', 'disclosure' => null, 'through' => false];

                continue;
            }
            $through = $senderKey !== null && in_array($senderKey, (array) ($s->always_through ?? []), true);
            if ($through) {
                $out[$key] = ['mode' => 'now', 'disclosure' => null, 'through' => true];

                continue;
            }
            $source = match (true) {
                (bool) $message->is_urgent => 'urgent',
                $conversation->isChannel() => 'channels',
                $conversation->isGroup() => in_array($p->id, $mentions, true) || ($repliedTo !== null && $repliedTo->sender_type === $p->participant_type && (int) $repliedTo->sender_id === (int) $p->participant_id) ? 'mentions' : 'groups',
                in_array($message->type, [MessageType::MoneyRequest, MessageType::BillSplit, MessageType::WalletTransfer], true) => 'money',
                isset($contacts[$key]) => 'contacts',
                default => 'strangers',
            };
            $attention = array_merge(self::DEFAULT, array_intersect_key((array) ($s->attention ?? []), array_flip(self::SOURCES)));
            $disclosure = null;
            if (($ctx = $s->activeContext()) !== null) {
                $c = self::context($s, $ctx);
                $attention = array_merge($attention, $c['attention']);
                $disclosure = $c['disclosure'];
            }
            $mode = in_array($attention[$source] ?? 'now', self::MODES, true) ? $attention[$source] : 'now';
            $out[$key] = ['mode' => $mode, 'disclosure' => $disclosure, 'through' => false];
        }

        return $out;
    }

    /**
     * Whether a call may ring for this person now (spec 155): my call hours and my context; people
     * I chose always ring.
     */
    public function mayRing(?ChatPrivacySetting $s, ?string $callerKey): bool
    {
        if ($s === null) {
            return true;
        }
        if ($callerKey !== null && in_array($callerKey, (array) ($s->always_through ?? []), true)) {
            return true;
        }
        if (! empty($s->call_hours['from']) && ! ChatPrivacySetting::inWindow($s->call_hours, now())) {
            return false;
        }
        $calls = ($ctx = $s->activeContext()) !== null ? self::context($s, $ctx)['calls'] : 'everyone';

        return $calls === 'everyone';
    }

    /** Count a message for my next summary. */
    public function addToDigest(string $ownerType, int $ownerId, string $conversationUuid): void
    {
        $row = DB::table('chat_attention_digests')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
        $chats = array_values(array_unique([...(json_decode((string) ($row->conversation_ids ?? '[]'), true) ?: []), $conversationUuid]));
        DB::table('chat_attention_digests')->updateOrInsert(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['messages_count' => ($row->messages_count ?? 0) + 1, 'conversation_ids' => json_encode($chats), 'first_at' => $row->first_at ?? now(), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()],
        );
    }

    /**
     * Recipients who have the sender in their contacts.
     *
     * @param  Collection<int, ChatParticipant>  $recipients
     * @return array<string, true>
     */
    private function contactsOfSender(Collection $recipients, ChatMessage $message): array
    {
        if ($message->sender_type === null) {
            return [];
        }
        $out = [];
        ChatContact::query()->where('contact_type', $message->sender_type)->where('contact_id', $message->sender_id)
            ->whereIn('owner_id', $recipients->pluck('participant_id'))->get(['owner_type', 'owner_id'])
            ->each(function ($c) use (&$out) {
                $out[$c->owner_type.':'.$c->owner_id] = true;
            });

        return $out;
    }
}
