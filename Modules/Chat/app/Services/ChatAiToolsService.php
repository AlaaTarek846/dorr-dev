<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Throwable;

/**
 * DORR AI tools in the chat (spec 31, 36–42, 46, 48, 49), all through the central DORR AI
 * provider and its safety rules (350–362) — never a second AI engine.
 *
 * Privacy (A.1, AT-PRIV-10/11): nothing is read in the background. Every call is one explicit tap
 * by the person, and only what that tap covers goes to the AI: the text being written, the one
 * message, the messages picked, or — for "important" and "today" across chats — the person's own
 * unread / today's messages, never from locked chats, locked or hidden circles, sensitive or
 * view-once messages. Results are suggestions: nothing is sent, saved or scheduled until the
 * person confirms it.
 */
class ChatAiToolsService
{
    /** Cross-chat reads (41, 49): at most this many chats and characters. */
    private const CROSS_CHATS = 20;

    private const CROSS_CHARS = 14000;

    public function __construct(
        private readonly ChatAiService $ai,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
    ) {}

    private function language(): string
    {
        return ChatAiService::LANGUAGES[app()->getLocale()] ?? 'English';
    }

    // ---------------------------------------------------------------- 31 the assistant in a chat

    /**
     * DORR AI inside a chat, only for me: a question (anything, or about the messages I picked),
     * answered under the safety rules. Nothing of the chat goes unless I picked it. `history` is
     * this little conversation so far (kept on the phone, not stored).
     *
     * @param  list<string>|null  $messageIds
     * @param  list<array{q: string, a: string}>  $history
     * @return array{answer: string, safety: ?array<string, mixed>, references: list<array<string, mixed>>}
     */
    public function assistant(Model $me, ChatConversation $conversation, string $question, ?array $messageIds, array $history = []): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $context = '';
        $numbered = collect();
        if (! empty($messageIds)) {
            [$numbered, $lines] = $this->ai->numbered($me, $participant, $conversation, $messageIds);
            $context = "Messages the person picked from their chat (numbered):\n".$lines->implode("\n")."\n\n";
        }

        $messages = [['role' => 'system', 'content' => 'You are DORR AI, a helpful assistant inside a chat app, answering only the person who asked (nobody else in the chat sees it). '
            .'Answer in '.$this->language().' unless they write in another language. Be clear and practical; short unless more is needed. '
            .'If messages are given, base what you say about them only on them and never invent what they do not say. '
            .'Reply with JSON only: {"answer": "<your answer>", "refs": [<numbers of the given messages you used>]}.']];
        foreach (array_slice($history, -5) as $turn) {
            $messages[] = ['role' => 'user', 'content' => Str::limit((string) ($turn['q'] ?? ''), 500)];
            $messages[] = ['role' => 'assistant', 'content' => Str::limit((string) ($turn['a'] ?? ''), 1500)];
        }
        $messages[] = ['role' => 'user', 'content' => $context.'Question: '.Str::limit(trim($question), 1000)];

        $safe = $this->ai->askSafely($messages, $question);
        $json = $this->ai->json($safe['raw']);
        $answer = is_string($json['answer'] ?? null) ? $this->ai->safety()->finish($json['answer'], $safe['risk'])['text'] : $safe['text'];

        return [
            'answer' => $answer,
            'safety' => $safe['safety'],
            'references' => $numbered->isEmpty() ? [] : $this->ai->references($me, $numbered, (array) ($json['refs'] ?? [])),
        ];
    }

    // ---------------------------------------------------------------- 36 spelling before sending

    /**
     * The text I'm writing, corrected (spelling and grammar only) — same language, dialect, tone,
     * emojis, @mentions and *formatting*. Nothing of the chat is read.
     *
     * @return array{text: string, changed: bool}
     */
    public function proofread(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new ChatException('ai_nothing_to_translate', 422);
        }

        $fixed = Cache::remember('chat:ai:proof:'.md5($text), now()->addDay(), fn () => $this->ai->ask([
            ['role' => 'system', 'content' => 'You correct spelling and grammar mistakes in a chat message before it is sent. '
                .'Keep the same language and dialect (colloquial Arabic stays colloquial), the meaning, tone, emojis, names, numbers, links, @mentions and *_~ formatting marks. '
                .'Do not rephrase, shorten or add anything. If there is nothing to fix, return it unchanged. Reply with the message only.'],
            ['role' => 'user', 'content' => Str::limit($text, 4000, '')],
        ]));
        $fixed = trim($fixed, " \t\n\"");

        return ['text' => $fixed, 'changed' => $fixed !== $text];
    }

    // ---------------------------------------------------------------- 37 + 42 understand a message

    /**
     * What a message is after (its intent), its tone, and what I could do — reply (three
     * suggestions), remind me, make it a task, keep a note. Suggestions only.
     *
     * @return array{intent: string, tone: string, summary: string, tone_note: ?string, actions: list<string>, replies: list<string>, safety: ?array<string, mixed>}
     */
    public function understand(Model $me, ChatMessage $message): array
    {
        $this->ai->readable($me, $message);
        $text = $this->textOf($message);
        $from = $this->senderName($me, $message);

        $safe = $this->ai->askSafely([
            ['role' => 'system', 'content' => 'You help someone understand a message they received in a chat, and answer in '.$this->language().'. '
                .'Reply with JSON only: {"intent": "question" | "request" | "invitation" | "appointment" | "payment" | "information" | "complaint" | "greeting" | "other", '
                .'"tone": "positive" | "neutral" | "negative" | "urgent" | "worried" | "angry" | "joking", '
                .'"summary": "<what the sender wants, in one short sentence>", "tone_note": "<one short sentence on the feeling and how to answer it, or null>", '
                .'"actions": [any of "reply", "remind", "task", "note"], "replies": [3 short replies the person could send, in the message\'s own language and dialect]}.'],
            ['role' => 'user', 'content' => 'From '.$from.":\n".$text],
        ], $text);

        $json = $this->ai->json($safe['raw']);
        $pick = fn (string $key, array $allowed, string $default) => in_array($json[$key] ?? null, $allowed, true) ? $json[$key] : $default;

        return [
            'intent' => $pick('intent', ['question', 'request', 'invitation', 'appointment', 'payment', 'information', 'complaint', 'greeting', 'other'], 'other'),
            'tone' => $pick('tone', ['positive', 'neutral', 'negative', 'urgent', 'worried', 'angry', 'joking'], 'neutral'),
            'summary' => Str::limit(trim((string) ($json['summary'] ?? '')), 300),
            'tone_note' => is_string($json['tone_note'] ?? null) && trim($json['tone_note']) !== '' ? Str::limit(trim($json['tone_note']), 300) : null,
            'actions' => array_values(array_intersect(['reply', 'remind', 'task', 'note'], (array) ($json['actions'] ?? []))),
            'replies' => ChatAiService::parseReplies(json_encode(array_values((array) ($json['replies'] ?? [])), JSON_UNESCAPED_UNICODE) ?: '[]'),
            'safety' => $safe['safety'],
        ];
    }

    // ---------------------------------------------------------------- 46 simplify a long message

    /**
     * @return array{short: string, points: list<string>}
     */
    public function simplify(Model $me, ChatMessage $message): array
    {
        $this->ai->readable($me, $message);
        $text = $this->textOf($message);

        $json = Cache::remember('chat:ai:simplify:'.$message->id.':'.app()->getLocale(), now()->addDays(7), fn () => $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You simplify a long chat message, in '.$this->language().'. Reply with JSON only: '
                .'{"short": "<the message in one or two simple sentences>", "points": ["<the key points, at most 6, short>"]}. Only what the message says.'],
            ['role' => 'user', 'content' => Str::limit($text, 6000, '')],
        ])));

        return [
            'short' => Str::limit(trim((string) ($json['short'] ?? '')), 500),
            'points' => collect((array) ($json['points'] ?? []))->filter(fn ($p) => is_string($p) && trim($p) !== '')->map(fn ($p) => Str::limit(trim($p), 200))->take(6)->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- 38 tasks from a message

    /**
     * The tasks in a message ("buy bread, call the plumber, send the file by Thursday"), ordered,
     * with a due time when it says one — to save to my tasks if I want.
     *
     * @return array{tasks: list<array{text: string, due_at: ?string}>}
     */
    public function tasksFrom(Model $me, ChatMessage $message, string $timezone): array
    {
        $this->ai->readable($me, $message);
        $now = now()->setTimezone($timezone)->format('Y-m-d H:i (l)');

        $items = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You turn a chat message into a short ordered to-do list. It is now '.$now.' in the person\'s time zone. '
                .'Reply with a JSON array only (empty if there is nothing to do): [{"text": "<the task, short, in the message\'s language>", "due": "YYYY-MM-DD HH:MM" or null}]. '
                .'Only what the message asks for; never invent a date.'],
            ['role' => 'user', 'content' => Str::limit($this->textOf($message), 4000, '')],
        ]));
        $items = array_is_list($items) ? $items : ($items['tasks'] ?? []);

        return ['tasks' => collect((array) $items)->filter(fn ($i) => is_array($i) && is_string($i['text'] ?? null) && trim($i['text']) !== '')->take(15)
            ->map(fn ($i) => ['text' => Str::limit(trim($i['text']), 200), 'due_at' => $this->due($i['due'] ?? null, $timezone)])->values()->all()];
    }

    // ---------------------------------------------------------------- 39 a note from messages

    /**
     * The useful facts in the message(s) as a short, organised note — saved into "Notes (you)" as
     * my own message, so it lives with my other notes.
     *
     * @param  list<string>  $messageIds
     * @return array{title: string, points: list<string>, message: ChatMessage}
     */
    public function note(Model $me, ChatConversation $conversation, array $messageIds): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        [, $lines] = $this->ai->numbered($me, $participant, $conversation, $messageIds);

        $json = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You make a short organised note from chat messages, in '.$this->language().'. Keep only the useful facts: names, dates, times, places, amounts, numbers, links, decisions. '
                .'Reply with JSON only: {"title": "<a short title>", "points": ["<one fact per point, at most 10>"]}. Never invent anything.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]));
        $title = Str::limit(trim((string) ($json['title'] ?? '')), 80) ?: __('chat.ai.note_title');
        $points = collect((array) ($json['points'] ?? []))->filter(fn ($p) => is_string($p) && trim($p) !== '')->map(fn ($p) => Str::limit(trim($p), 300))->take(10)->values()->all();
        if ($points === []) {
            throw new ChatException('ai_failed', 502);
        }

        $notes = $this->conversations->openSelf($me)->conversation;
        $body = '📝 *'.$title."*\n".collect($points)->map(fn ($p) => '• '.$p)->implode("\n");
        $message = $this->messages->send($me, $notes, ['type' => MessageType::Text->value, 'body' => Str::limit($body, 4000, '')]);

        return ['title' => $title, 'points' => $points, 'message' => $message];
    }

    // ---------------------------------------------------------------- 40 dates mentioned in a chat

    /**
     * Appointments mentioned in the chat ("the dentist on Sunday at 4"), with their time in my
     * zone — each one becomes a reminder only when I tap it.
     *
     * @param  list<string>|null  $messageIds
     * @return array{dates: list<array<string, mixed>>, messages: int}
     */
    public function dates(Model $me, ChatConversation $conversation, ?array $messageIds, string $timezone): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        [$numbered, $lines] = $this->ai->numbered($me, $participant, $conversation, $messageIds);
        $now = now()->setTimezone($timezone)->format('Y-m-d H:i (l)');

        $items = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You find appointments and dates in a chat: meetings, visits, deadlines, events, calls. It is now '.$now.' in the person\'s time zone. '
                .'Reply with a JSON array only (empty if none): [{"title": "<what, short, in the chat\'s language>", "at": "YYYY-MM-DD HH:MM", "all_day": true | false, "ref": <number of the message>}]. '
                .'Only dates the chat really gives (resolve "tomorrow", "Sunday" from now); an all-day one gets 09:00. Never invent one.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]));
        $items = array_is_list($items) ? $items : ($items['dates'] ?? []);

        $out = [];
        foreach (array_slice((array) $items, 0, 20) as $item) {
            if (! is_array($item) || ! is_string($item['title'] ?? null) || ($at = $this->due($item['at'] ?? null, $timezone)) === null) {
                continue;
            }
            $out[] = [
                'title' => Str::limit(trim($item['title']), 200),
                'at' => $at,
                'all_day' => (bool) ($item['all_day'] ?? false),
                'message_id' => $numbered->get((int) ($item['ref'] ?? 0))?->uuid,
            ];
        }

        return ['dates' => $out, 'messages' => $numbered->count()];
    }

    // ---------------------------------------------------------------- 41 what's important

    /**
     * The messages that deserve attention — in one chat (its unread, or its latest), or across my
     * unread chats — with why. Locked chats, locked or hidden circles, sensitive and view-once
     * messages never go.
     *
     * @return array{items: list<array<string, mixed>>, messages: int, chats: int}
     */
    public function important(Model $me, ?ChatConversation $conversation): array
    {
        $rows = $conversation !== null
            ? $this->fromChat($me, $conversation)
            : $this->acrossChats($me, fn ($q, ChatParticipant $p) => $q->where('chat_messages.id', '>', (int) $p->last_read_message_id), 40);
        [$numbered, $lines, $chats] = $this->lines($me, $rows);

        $items = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You pick the chat messages that deserve the person\'s attention ("Me" is them): questions or requests waiting for them, deadlines, money, '
                .'appointments, problems, anything urgent or from someone close. Skip greetings, chit-chat and things already answered. Answer in '.$this->language().'. '
                .'Reply with a JSON array only, most important first, at most 10: [{"ref": <message number>, "level": "high" | "medium", "why": "<a few words>"}].'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]));
        $items = array_is_list($items) ? $items : ($items['items'] ?? []);

        $out = [];
        foreach (array_slice((array) $items, 0, 10) as $item) {
            $m = is_array($item) ? $numbered->get((int) ($item['ref'] ?? 0)) : null;
            if ($m === null || isset($out[$m->id])) {
                continue;
            }
            $out[$m->id] = $this->refRow($me, $m, $chats) + [
                'level' => ($item['level'] ?? '') === 'high' ? 'high' : 'medium',
                'why' => Str::limit(trim((string) ($item['why'] ?? '')), 160),
            ];
        }

        return ['items' => array_values($out), 'messages' => $numbered->count(), 'chats' => $chats->count()];
    }

    // ---------------------------------------------------------------- 48 related files

    /**
     * Files shared before in this chat that fit what it's about now. Only the latest messages and
     * the files' names / captions go to the AI — never the files themselves.
     *
     * @return array{files: list<array<string, mixed>>}
     */
    public function relatedFiles(Model $me, ChatConversation $conversation): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $types = [MessageType::Document, MessageType::Image, MessageType::Video, MessageType::Audio];
        $files = $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereIn('type', array_map(fn ($t) => $t->value, $types))->whereNull('deleted_for_everyone_at')->where('view_once', false)->where('is_sensitive', false)
            ->with('media')->orderByDesc('id')->limit(150)->get()->values();
        if ($files->isEmpty()) {
            return ['files' => []];
        }

        $recent = $this->ai->transcript($me, $participant, $conversation, 20);
        $list = $files->map(function (ChatMessage $m, int $i) {
            $media = $m->getFirstMedia(ChatMessage::ATTACHMENTS);

            return '['.($i + 1).'] '.$m->type->value.' "'.Str::limit((string) ($media?->file_name ?? ''), 80).'" '.$m->created_at?->format('Y-m-d').($m->body ? ' — '.Str::limit(ChatPushNotifier::plain((string) $m->body), 120) : '');
        });

        $items = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You suggest files shared earlier in a chat that relate to what the latest messages are about. Use only names, types, dates and captions. '
                .'Answer in '.$this->language().'. Reply with a JSON array only, best first, at most 6 (empty if none fit): [{"ref": <file number>, "why": "<a few words>"}].'],
            ['role' => 'user', 'content' => "Latest messages:\n".$recent->implode("\n")."\n\nFiles:\n".$list->implode("\n")],
        ]));
        $items = array_is_list($items) ? $items : ($items['files'] ?? []);

        $out = [];
        foreach (array_slice((array) $items, 0, 6) as $item) {
            $m = is_array($item) ? $files->get((int) ($item['ref'] ?? 0) - 1) : null;
            if ($m === null || isset($out[$m->id])) {
                continue;
            }
            $media = $m->getFirstMedia(ChatMessage::ATTACHMENTS);
            $out[$m->id] = [
                'message_id' => $m->uuid,
                'type' => $m->type->value,
                'name' => $media?->file_name,
                'url' => $media?->getUrl(),
                'created_at' => $m->created_at?->toIso8601String(),
                'why' => Str::limit(trim((string) ($item['why'] ?? '')), 160),
            ];
        }

        return ['files' => array_values($out)];
    }

    // ---------------------------------------------------------------- 49 today in all my chats

    /**
     * Today across my chats (in my time zone): a short summary with the highlights and decisions,
     * each pointing at its chat. On a tap only.
     *
     * @return array{summary: list<string>, highlights: list<array<string, mixed>>, messages: int, chats: int}
     */
    public function today(Model $me, string $timezone): array
    {
        $from = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $rows = $this->acrossChats($me, fn ($q) => $q->where('chat_messages.created_at', '>=', $from), 60);
        [$numbered, $lines, $chats] = $this->lines($me, $rows);

        $json = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You write "my day in chats" for the person ("Me"), in '.$this->language().', from today\'s messages grouped by chat. '
                .'Reply with JSON only: {"summary": ["<3 to 7 short bullet points: what happened, what was decided, what waits for Me>"], '
                .'"highlights": [{"ref": <message number>, "text": "<why it matters, a few words>"}] (at most 8)}. Only what the messages say.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]));

        $highlights = [];
        foreach (array_slice((array) ($json['highlights'] ?? []), 0, 8) as $h) {
            $m = is_array($h) ? $numbered->get((int) ($h['ref'] ?? 0)) : null;
            if ($m !== null && ! isset($highlights[$m->id])) {
                $highlights[$m->id] = $this->refRow($me, $m, $chats) + ['text' => Str::limit(trim((string) ($h['text'] ?? '')), 200)];
            }
        }

        return [
            'summary' => collect((array) ($json['summary'] ?? []))->filter(fn ($p) => is_string($p) && trim($p) !== '')->map(fn ($p) => Str::limit(trim($p), 300))->take(7)->values()->all(),
            'highlights' => array_values($highlights),
            'messages' => $numbered->count(),
            'chats' => $chats->count(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function textOf(ChatMessage $message): string
    {
        $text = trim(ChatPushNotifier::plain((string) $message->body));
        if ($text === '') {
            throw new ChatException('ai_nothing_to_translate', 422);
        }

        return $text;
    }

    private function senderName(Model $me, ChatMessage $message): string
    {
        return $message->sender_type ? (string) ($this->directory->profile($me, $message->sender_type, $message->sender_id)['name'] ?? '?') : '?';
    }

    private function due(mixed $value, string $timezone): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($value, $timezone)->utc();
        } catch (Throwable) {
            return null;
        }

        return $at->isFuture() ? $at->toIso8601String() : null;
    }

    /**
     * One chat: its unread when there are a few, else its latest messages.
     *
     * @return Collection<int, ChatMessage>
     */
    private function fromChat(Model $me, ChatConversation $conversation): Collection
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $base = fn () => $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereNotNull('sender_type')->whereNull('deleted_for_everyone_at')->where('view_once', false)->where('is_sensitive', false);
        $unread = $base()->where('id', '>', (int) $participant->last_read_message_id)->orderByDesc('id')->limit(120)->get();
        $rows = $unread->count() >= 3 ? $unread : $base()->orderByDesc('id')->limit(80)->get();
        $rows->each(fn ($m) => $m->setRelation('conversation', $conversation));

        return $rows->reverse()->values();
    }

    /**
     * My messages across chats for one condition (unread / today) — never from locked chats,
     * locked or hidden circles, nor sensitive, view-once or system messages.
     *
     * @return Collection<int, ChatMessage>
     */
    private function acrossChats(Model $me, callable $where, int $perChat): Collection
    {
        $participants = ChatParticipant::query()
            ->where('participant_type', ParticipantType::aliasFor($me))->where('participant_id', $me->getKey())
            ->whereNull('left_at')->where('is_deleted', false)->where('is_locked', false)
            ->where(fn ($q) => $q->whereNull('privacy_circle_id')->orWhereHas('privacyCircle', fn ($c) => $c->where('locked', false)->where('hide_from_list', false)))
            ->whereHas('conversation', fn ($c) => $c->whereNotNull('last_message_id'))
            ->with('conversation.group')
            ->get()
            ->sortByDesc(fn ($p) => $p->conversation?->last_message_id)
            ->take(self::CROSS_CHATS * 2);

        $rows = collect();
        foreach ($participants as $p) {
            $conversation = $p->conversation;
            if ($conversation === null || $conversation->isSelf()) {
                continue;
            }
            $found = $where($this->messages->visibleTo($p, $conversation->messages()->getQuery()), $p)
                ->whereNotNull('sender_type')->whereNull('deleted_for_everyone_at')->where('view_once', false)->where('is_sensitive', false)
                ->orderByDesc('id')->limit($perChat)->get();
            if ($found->isEmpty()) {
                continue;
            }
            $found->each(fn ($m) => $m->setRelation('conversation', $conversation));
            $rows = $rows->concat($found->reverse()->values());
            if ($rows->pluck('conversation_id')->unique()->count() >= self::CROSS_CHATS) {
                break;
            }
        }

        if ($rows->isEmpty()) {
            throw new ChatException('ai_nothing_to_summarize', 422);
        }

        return $rows;
    }

    /**
     * Numbered lines grouped by chat ("## Sara", "[3] 14:05 Sara: …"), within the size limit.
     *
     * @param  Collection<int, ChatMessage>  $rows
     * @return array{0: Collection<int, ChatMessage>, 1: Collection<int, string>, 2: Collection<int, array{id: string, title: ?string}>}
     */
    private function lines(Model $me, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            throw new ChatException('ai_nothing_to_summarize', 422);
        }
        $this->directory->prime($me, $rows->map(fn ($m) => [$m->sender_type, $m->sender_id]));
        $myType = ParticipantType::aliasFor($me);

        $numbered = collect();
        $lines = collect();
        $chats = collect();
        $chars = 0;
        $n = 0;
        foreach ($rows->groupBy('conversation_id') as $group) {
            $conversation = $group->first()->conversation;
            $title = $this->chatTitle($me, $conversation);
            $chats->put($conversation->id, ['id' => $conversation->uuid, 'title' => $title]);
            $lines->push('## '.($title ?? '?'));
            foreach ($group as $m) {
                $who = $m->isFrom($myType, (int) $me->getKey()) ? 'Me' : $this->senderName($me, $m);
                $what = trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']';
                $line = '['.(++$n).'] '.$m->created_at?->format('H:i').' '.$who.': '.Str::limit($what, 400);
                $chars += mb_strlen($line);
                if ($chars > self::CROSS_CHARS) {
                    break 2;
                }
                $numbered->put($n, $m);
                $lines->push($line);
            }
        }

        return [$numbered, $lines, $chats];
    }

    private function chatTitle(Model $me, ChatConversation $conversation): ?string
    {
        if ($conversation->isGroup()) {
            return $conversation->group?->name;
        }
        $other = $conversation->activeParticipants()->get()->first(fn ($p) => ! ($p->participant_type === ParticipantType::aliasFor($me) && (int) $p->participant_id === (int) $me->getKey()));

        return $other ? ($this->directory->profile($me, $other->participant_type, $other->participant_id)['name'] ?? null) : null;
    }

    /**
     * @param  Collection<int, array{id: string, title: ?string}>  $chats
     * @return array<string, mixed>
     */
    private function refRow(Model $me, ChatMessage $m, Collection $chats): array
    {
        return [
            'message_id' => $m->uuid,
            'conversation_id' => $chats->get($m->conversation_id)['id'] ?? $m->conversation?->uuid,
            'chat' => $chats->get($m->conversation_id)['title'] ?? null,
            'sender' => $this->senderName($me, $m),
            'excerpt' => Str::limit(trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']', 140),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
