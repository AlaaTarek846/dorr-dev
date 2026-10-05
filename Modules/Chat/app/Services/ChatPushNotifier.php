<?php

namespace Modules\Chat\Services;

use App\Models\NotificationDevice;
use App\Support\LocaleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Support\ParticipantType;
use Throwable;

/**
 * Phone push for the chat (OneSignal, through the project's sendPushNotification()).
 *
 * Unlike NotificationCenter, a chat message never goes into the in-app notifications list — the
 * chat list *is* its inbox. Respects, per recipient: mute, locked chats (no content), and the
 * `notification_privacy` setting (all · name only · nothing). Everything is best-effort: a push
 * problem never fails sending the message.
 */
class ChatPushNotifier
{
    /**
     * @param  Collection<int, ChatParticipant>  $recipients
     */
    public function message(ChatConversation $conversation, ChatMessage $message, string $senderName, Collection $recipients): void
    {
        // An urgent message (the recipient allowed it) gets through a mute.
        $recipients = $recipients->reject(fn (ChatParticipant $p) => $p->isMuted() && ! $message->is_urgent)->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $groupName = $conversation->isGroup() ? $conversation->group?->name : null;
        $title = $groupName !== null ? "{$senderName} @ {$groupName}" : $senderName;
        if ($message->is_urgent) {
            $title = '🚨 '.$title;
        }

        $data = [
            'type' => 'chat',
            'event' => 'chat.message.sent',
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $message->uuid,
            // The app shows it without a sound or vibration (the sender sent it silently).
            'silent' => $message->is_silent ? '1' : '0',
            'urgent' => $message->is_urgent ? '1' : '0',
        ];

        // Three audiences at most: the name and the text · the name and "New message" · "Dorr",
        // "New message". A locked chat never shows anything.
        $levels = $this->privacyLevels($recipients);
        $level = function (ChatParticipant $p) use ($levels, $message) {
            $chosen = $p->is_locked ? 'none' : ($levels[$p->participant_type.':'.$p->participant_id] ?? 'all');

            // A sensitive message never shows its content, whatever the recipient chose.
            return $chosen === 'all' && $message->is_sensitive ? 'name' : $chosen;
        };
        $groups = $recipients->groupBy($level);

        $this->send($groups->get('all', collect()), ['en' => $title], $this->previewInEveryLanguage($message), $data);
        $this->send($groups->get('name', collect()), ['en' => $title], $this->inEveryLanguage('chat.push.new_message_body'), $data);
        // Nothing shown (or privacy mode on): `private = 1` lets the phone fold them into one
        // "N new messages" notification that names nobody (spec 104).
        $this->send($groups->get('none', collect()), $this->inEveryLanguage('chat.push.new_message_title'), $this->inEveryLanguage('chat.push.new_message_body'), $data + ['private' => '1']);
    }

    /**
     * High-priority push for an incoming call (the phone may be asleep).
     *
     * @param  Collection<int, ChatParticipant>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function call(string $callerName, bool $video, Collection $recipients, array $data): void
    {
        $body = $this->inEveryLanguage($video ? 'chat.push.incoming_video_call' : 'chat.push.incoming_voice_call');

        // The app turns this into a full-screen ringing call (it needs the caller's name itself),
        // so it must arrive now or never: top priority, and dropped after the ring timeout.
        $this->send($recipients, ['en' => $callerName], $body, $data + ['type' => 'chat_call', 'caller_name' => $callerName], [
            'priority' => 10,
            'ttl' => (int) config('chat.call_ring_timeout_seconds', 45),
        ]);
    }

    /**
     * @param  Collection<int, ChatParticipant>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function missedCall(string $callerName, bool $video, Collection $recipients, array $data): void
    {
        $body = $this->inEveryLanguage($video ? 'chat.push.missed_video_call' : 'chat.push.missed_voice_call');

        $this->send($recipients->reject(fn (ChatParticipant $p) => $p->isMuted()), ['en' => $callerName], $body, $data + ['type' => 'chat_call']);
    }

    /**
     * @param  Collection<int, ChatParticipant>  $recipients
     * @param  array<string, string>  $headings
     * @param  array<string, string>  $contents
     * @param  array<string, mixed>  $data
     */
    /**
     * "You asked to be reminded": the note (or the message's text) to the one person who set it.
     * The text follows their notification privacy — "none" says only that there's a reminder.
     */
    public function reminder(ChatParticipant $owner, ChatMessage $message, ?string $note): void
    {
        $level = ChatPrivacySetting::query()->where('owner_type', $owner->participant_type)->where('owner_id', $owner->participant_id)->value('notification_privacy') ?: 'all';
        $text = $note ?: ($message->type === MessageType::Text ? Str::limit(self::plain((string) $message->body), 140) : null);

        $this->send(
            collect([$owner]),
            $this->inEveryLanguage('chat.push.reminder_title'),
            $level === 'all' && $text ? ['en' => $text] : $this->inEveryLanguage('chat.push.reminder_body'),
            [
                'type' => 'chat',
                'event' => 'chat.reminder.due',
                'conversation_uuid' => $message->conversation?->uuid,
                'message_uuid' => $message->uuid,
                'silent' => '0',
                'urgent' => '0',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options  extra OneSignal fields (priority, ttl…)
     */
    private function send(Collection $recipients, array $headings, array $contents, array $data, array $options = []): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($recipients, $headings, $contents, $data, $options) {
            try {
                $playerIds = NotificationDevice::query()
                    ->where(function ($q) use ($recipients) {
                        foreach ($recipients->groupBy('participant_type') as $type => $rows) {
                            $q->orWhere(fn ($q) => $q->where('owner_type', ParticipantType::modelClassFor($type))->whereIn('owner_id', $rows->pluck('participant_id')));
                        }
                    })
                    ->pluck('player_id')->filter()->unique()->values()->all();

                if ($playerIds === []) {
                    return;
                }

                // OneSignal accepts up to 2000 devices per request.
                foreach (array_chunk($playerIds, 2000) as $chunk) {
                    defer(fn () => sendPushNotification(message: $contents, playerIds: $chunk, title: $headings, data: $data, options: $options));
                }
            } catch (Throwable $e) {
                Log::error('[ChatPushNotifier] '.$e->getMessage());
            }
        });
    }

    /**
     * Everyone's `notification_privacy` in one query, keyed "user:7" (no row = all).
     *
     * @param  Collection<int, ChatParticipant>  $recipients
     * @return array<string, string>
     */
    private function privacyLevels(Collection $recipients): array
    {
        $levels = [];

        ChatPrivacySetting::query()
            ->where(function ($q) use ($recipients) {
                foreach ($recipients->groupBy('participant_type') as $type => $rows) {
                    $q->orWhere(fn ($q) => $q->where('owner_type', $type)->whereIn('owner_id', $rows->pluck('participant_id')));
                }
            })
            ->get(['owner_type', 'owner_id', 'notification_privacy', 'privacy_mode_until', 'privacy_schedule'])
            ->each(function (ChatPrivacySetting $s) use (&$levels) {
                // Privacy mode (now, or by schedule) shows nothing, whatever the usual level.
                $levels[$s->owner_type.':'.$s->owner_id] = $s->privacyModeOn() ? 'none' : ($s->notification_privacy ?: 'all');
            });

        return $levels;
    }

    /**
     * @return array<string, string>
     */
    private function previewInEveryLanguage(ChatMessage $message): array
    {
        if ($message->type === MessageType::Text) {
            return ['en' => Str::limit(self::plain((string) $message->body), 180)];
        }

        // A view-once photo never shows its caption in a notification; a live location says it's live.
        $key = match (true) {
            $message->view_once => 'view_once',
            $message->type === MessageType::Location && data_get($message->meta, 'live_until') !== null => 'live_location',
            default => $message->type->value,
        };
        $texts = $this->inEveryLanguage('chat.preview.'.$key);

        if ($message->body && ! $message->view_once) {
            foreach ($texts as $locale => $label) {
                $texts[$locale] = $label.' · '.Str::limit(self::plain((string) $message->body), 140);
            }
        }

        return $texts;
    }

    /**
     * The text without its formatting marks (*bold*, _italic_, ~strike~, `code`, ```mono```), the
     * same rules as the apps: a mark only counts at a word edge and around non-blank text.
     */
    public static function plain(string $body): string
    {
        $body = preg_replace('/```([\s\S]+?)```/u', '$1', $body) ?? $body;

        foreach (['`', '*', '_', '~'] as $mark) {
            $m = preg_quote($mark, '/');
            $body = preg_replace("/(?<=^|[\\s\\p{P}]){$m}(?=\\S)([^{$m}\\n]*?\\S){$m}(?=$|[\\s\\p{P}])/u", '$1', $body) ?? $body;
        }

        // Line marks: "> " quotes lose their mark, "- " / "* " list items read as "• ".
        $body = preg_replace('/^> /mu', '', $body) ?? $body;

        return preg_replace('/^[-*] /mu', '• ', $body) ?? $body;
    }

    /**
     * @return array<string, string>
     */
    private function inEveryLanguage(string $key): array
    {
        $texts = [];

        foreach (LocaleResolver::supported() as $locale) {
            $texts[$locale] = (string) __($key, [], $locale);
        }

        return $texts;
    }
}
