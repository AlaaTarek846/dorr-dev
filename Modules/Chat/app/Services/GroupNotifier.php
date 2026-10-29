<?php

namespace Modules\Chat\Services;

use App\Support\LocaleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;

/**
 * Notices from educational and work groups (docs/groups_tasks.md GR-009) — one place for every
 * feature: a live event to whoever is concerned (the open app reloads once, nothing asks on a
 * timer), and a push (OneSignal) unless the member muted that kind of notice in this group.
 *
 * Messages in the chat (announcements included) keep the chat's own push and mute.
 */
class GroupNotifier
{
    /** The kinds a member can mute, per group. Later features add theirs. */
    public const TYPES = ['ownership', 'spaces', 'roles', 'tasks', 'assignments', 'quizzes', 'sessions', 'grades', 'help', 'shifts', 'ideas', 'meetings', 'parents'];

    public function __construct(
        private readonly ChatBroadcaster $broadcaster,
        private readonly ChatPushNotifier $push,
    ) {}

    /**
     * @param  iterable<ChatParticipant>  $recipients
     * @param  array<string, mixed>  $replace  for the push text (`chat.push.{key}_title` / `_body`)
     * @param  array<string, mixed>  $data  sent with the event and the push
     */
    public function notify(ChatConversation $conversation, iterable $recipients, string $type, string $key, array $replace = [], array $data = []): void
    {
        $recipients = collect($recipients)->filter(fn ($p) => $p instanceof ChatParticipant && $p->left_at === null)->unique('id')->values();
        if ($recipients->isEmpty()) {
            return;
        }

        $payload = ['conversation_id' => $conversation->uuid, 'type' => $type] + $data;
        $send = function () use ($conversation, $recipients, $type, $key, $replace, $payload) {
            $this->broadcaster->toParticipants($recipients, 'chat.group.notice', $payload);

            $pushTo = $this->unmuted($recipients, $type);
            if ($pushTo->isEmpty()) {
                return;
            }

            $headings = [];
            $contents = [];
            foreach (LocaleResolver::supported() as $locale) {
                $headings[$locale] = __("chat.push.{$key}_title", $replace, $locale);
                $contents[$locale] = __("chat.push.{$key}_body", $replace + ['group' => (string) $conversation->group?->name], $locale);
            }
            $this->push->toAccounts(
                $pushTo->map(fn (ChatParticipant $p) => [$p->participant_type, (int) $p->participant_id])->all(),
                $headings, $contents,
                ['type' => 'chat', 'event' => 'chat.group.notice'] + $payload,
            );
        };

        DB::afterCommit($send);
    }

    /**
     * @return list<string> the kinds I muted in this group
     */
    public function mutedFor(ChatParticipant $participant): array
    {
        return DB::table('chat_group_notification_prefs')->where('participant_id', $participant->id)->pluck('type')->all();
    }

    /**
     * @param  array<string, bool>  $prefs  type => pushed?
     * @return list<string> muted now
     */
    public function setPrefs(ChatParticipant $participant, array $prefs): array
    {
        foreach (array_intersect_key($prefs, array_flip(self::TYPES)) as $type => $pushed) {
            if ($pushed) {
                DB::table('chat_group_notification_prefs')->where('participant_id', $participant->id)->where('type', $type)->delete();
            } else {
                DB::table('chat_group_notification_prefs')->insertOrIgnore(['participant_id' => $participant->id, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $this->mutedFor($participant);
    }

    /**
     * @param  Collection<int, ChatParticipant>  $recipients
     * @return Collection<int, ChatParticipant>
     */
    private function unmuted(Collection $recipients, string $type): Collection
    {
        $muted = DB::table('chat_group_notification_prefs')->whereIn('participant_id', $recipients->pluck('id'))->where('type', $type)->pluck('participant_id')->all();

        return $recipients->reject(fn (ChatParticipant $p) => in_array($p->id, $muted, true))->values();
    }
}
