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

        // Smart quiet (spec 115): in someone's quiet time a message that isn't urgent makes no
        // notification — it's counted for the summary sent when the quiet time ends.
        if (! $message->is_urgent && $recipients->isNotEmpty()) {
            $quiet = $this->inQuietTime($recipients, $conversation);
            if ($quiet !== []) {
                $recipients = $recipients->reject(fn (ChatParticipant $p) => isset($quiet[$p->participant_type.':'.$p->participant_id]))->values();
            }
        }

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
        $circles = \Modules\Chat\Models\ChatPrivacyCircle::query()->whereIn('id', $recipients->pluck('privacy_circle_id')->filter()->unique())->get()->keyBy('id');
        $level = function (ChatParticipant $p) use ($levels, $message, $circles) {
            $chosen = $p->is_locked ? 'none' : ($levels[$p->participant_type.':'.$p->participant_id] ?? 'all');

            // A sensitive message never shows its content, whatever the recipient chose.
            $chosen = $chosen === 'all' && $message->is_sensitive ? 'name' : $chosen;

            // My circle for this chat (spec 98–103): its own level, the stricter of the two wins;
            // "circle" (P2) shows the circle's (stand-in) name instead of the person.
            $circle = $p->privacy_circle_id ? $circles->get($p->privacy_circle_id) : null;
            if ($circle !== null) {
                $chosen = \Modules\Chat\Models\ChatPrivacyCircle::stricter($chosen, $circle->disclosure);
                if ($chosen === 'circle') {
                    return 'circle:'.$circle->id;
                }
            }

            return $chosen === 'circle' ? 'name' : $chosen;
        };
        $groups = $recipients->groupBy($level);

        $this->send($groups->get('all', collect()), ['en' => $title], $this->previewInEveryLanguage($message), $data);
        $this->send($groups->get('name', collect()), ['en' => $title], $this->inEveryLanguage('chat.push.new_message_body'), $data);
        // P2: "New message — Friends" (the circle's stand-in name when it has one), nobody named.
        foreach ($groups as $key => $rows) {
            if (str_starts_with((string) $key, 'circle:')) {
                $circle = $circles->get((int) substr($key, 7));
                $this->send($rows, ['en' => $circle->shownName()], $this->inEveryLanguage('chat.push.new_message_body'), $data + ['circle' => $circle->uuid]);
            }
        }
        // Nothing shown (or privacy mode on): `private = 1` lets the phone fold them into one
        // "N new messages" notification that names nobody (spec 104).
        $this->send($groups->get('none', collect()), $this->inEveryLanguage('chat.push.new_message_title'), $this->inEveryLanguage('chat.push.new_message_body'), $data + ['private' => '1']);
        // P4: no notification at all — the phone only updates its counter.
        $this->send($groups->get('hidden', collect()), $this->inEveryLanguage('chat.push.new_message_title'), $this->inEveryLanguage('chat.push.new_message_body'), $data + ['private' => '1', 'hidden' => '1']);
    }

    /**
     * Who among these is in their quiet time for this chat — and counts the message for them.
     *
     * @param  Collection<int, ChatParticipant>  $recipients
     * @return array<string, true>
     */
    private function inQuietTime(Collection $recipients, ChatConversation $conversation): array
    {
        $quiet = [];

        ChatPrivacySetting::query()->whereNotNull('quiet_schedule')
            ->where(function ($q) use ($recipients) {
                foreach ($recipients->groupBy('participant_type') as $type => $rows) {
                    $q->orWhere(fn ($q) => $q->where('owner_type', $type)->whereIn('owner_id', $rows->pluck('participant_id')));
                }
            })
            ->get()
            ->each(function (ChatPrivacySetting $s) use (&$quiet, $conversation) {
                if (! $s->quietOn() || (($s->quiet_scope ?: 'all') === 'groups' && ! $conversation->isGroup())) {
                    return;
                }
                $quiet[$s->owner_type.':'.$s->owner_id] = true;

                $row = DB::table('chat_quiet_digests')->where('owner_type', $s->owner_type)->where('owner_id', $s->owner_id)->first();
                $chats = array_values(array_unique([...(json_decode((string) ($row->conversation_ids ?? '[]'), true) ?: []), $conversation->uuid]));
                DB::table('chat_quiet_digests')->updateOrInsert(
                    ['owner_type' => $s->owner_type, 'owner_id' => $s->owner_id],
                    ['messages_count' => ($row->messages_count ?? 0) + 1, 'conversation_ids' => json_encode($chats), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()],
                );
            });

        return $quiet;
    }

    /** "Ahmed invited you to sign a card for Sara" (spec 163). */
    public function collabInvite(string $organiser, string $title, \Illuminate\Support\Collection $members, string $cardId): void
    {
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $contents[$locale] = __('chat.push.collab_invite_body', ['name' => $organiser, 'title' => $title], $locale);
        }

        $this->send($members->map(fn ($m) => (object) ['participant_type' => $m->member_type, 'participant_id' => $m->member_id]), $this->inEveryLanguage('chat.push.collab_invite_title'), $contents, ['type' => 'chat', 'event' => 'chat.collab.invited', 'card_id' => $cardId]);
    }

    /**
     * "🎂 Sara's birthday — tomorrow" (my own date, DORR Moments): N days before and on the day.
     * Tapping it opens Moments, where the card for that person is one tap away.
     */
    public function momentReminder(\Illuminate\Database\Eloquent\Model $owner, \Modules\Chat\Models\ChatPersonalMoment $moment, int $daysLeft): void
    {
        $emoji = MomentService::PERSONAL_LOOKS[$moment->kind]['emoji'] ?? '✨';
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = $emoji.' '.$moment->title;
            $contents[$locale] = match (true) {
                $daysLeft === 0 => __('chat.push.moment_today', [], $locale),
                $daysLeft === 1 => __('chat.push.moment_tomorrow', [], $locale),
                default => __('chat.push.moment_in_days', ['days' => $daysLeft], $locale),
            };
        }

        $this->send(
            collect([(object) ['participant_type' => ParticipantType::aliasFor($owner), 'participant_id' => $owner->getKey()]]),
            $headings,
            $contents,
            ['type' => 'moments', 'event' => 'chat.moment.reminder', 'personal_id' => $moment->uuid, 'days_left' => (string) $daysLeft],
        );
    }

    /**
     * "📅 Dentist — in 30 minutes" (DORR Calendar, spec 204). The time is shown where I am now.
     */
    public function calendarReminder(\Modules\Chat\Models\ChatCalendarItem $item, \Illuminate\Database\Eloquent\Model $owner, int $minutesLeft): void
    {
        $zone = in_array($owner->timezone ?? '', timezone_identifiers_list(), true) ? $owner->timezone : (string) config('app.timezone', 'UTC');
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $contents[$locale] = match (true) {
                $item->all_day => __('chat.push.calendar_all_day', [], $locale),
                $minutesLeft <= 1 => __('chat.push.calendar_now', [], $locale),
                $minutesLeft < 120 => __('chat.push.calendar_in_minutes', ['minutes' => $minutesLeft], $locale),
                default => __('chat.push.calendar_at', ['time' => $item->starts_at->setTimezone($zone)->format('H:i'), 'date' => $item->starts_at->setTimezone($zone)->format('d/m')], $locale),
            }.($item->location ? ' · '.$item->location : '');
        }

        $this->send(
            collect([(object) ['participant_type' => $item->owner_type, 'participant_id' => $item->owner_id]]),
            array_fill_keys(LocaleResolver::supported(), '📅 '.$item->title),
            $contents,
            ['type' => 'calendar', 'event' => 'chat.calendar.reminder', 'item_id' => $item->uuid],
        );
    }

    /**
     * A notice to some accounts outside any chat (DORR Discover: an event I'm interested in
     * changed, one I shouldn't miss) — same devices, same OneSignal path.
     *
     * @param  iterable<array{0: string, 1: int}>  $owners  [alias, id]
     * @param  array<string, string>  $headings  per locale
     * @param  array<string, string>  $contents  per locale
     * @param  array<string, mixed>  $data
     */
    public function toAccounts(iterable $owners, array $headings, array $contents, array $data): void
    {
        $this->send(collect($owners)->map(fn ($o) => (object) ['participant_type' => $o[0], 'participant_id' => $o[1]])->values(), $headings, $contents, $data);
    }

    /** "✅ Call the plumber" — one of my tasks whose time came (spec 38). */
    public function taskDue(\Modules\Chat\Models\ChatTask $task): void
    {
        $this->send(
            collect([(object) ['participant_type' => $task->owner_type, 'participant_id' => $task->owner_id]]),
            $this->inEveryLanguage('chat.push.task_title'),
            ['en' => $task->text],
            ['type' => 'tasks', 'event' => 'chat.task.due', 'task_id' => $task->uuid],
        );
    }

    /** "While it was quiet: 12 messages in 4 chats" — once the quiet time is over. */
    public function quietDigest(string $ownerType, int $ownerId, int $messages, int $chats): void
    {
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $contents[$locale] = __('chat.push.quiet_digest_body', ['messages' => $messages, 'chats' => $chats], $locale);
        }

        $this->send(collect([(object) ['participant_type' => $ownerType, 'participant_id' => $ownerId]]), $this->inEveryLanguage('chat.push.quiet_digest_title'), $contents, ['type' => 'chat', 'event' => 'chat.quiet.digest']);
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

        // A surprise card shows nothing but that it's a surprise.
        if ($message->type === MessageType::MomentCard && data_get($message->meta, 'card.reveal_at') && \Illuminate\Support\Carbon::parse(data_get($message->meta, 'card.reveal_at'))->isFuture()) {
            return $this->inEveryLanguage('chat.preview.moment_card_sealed');
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
