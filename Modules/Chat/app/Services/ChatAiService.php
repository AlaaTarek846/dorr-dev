<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Safety\RiskAssessment;
use Modules\AI\Safety\SafetyPolicyEngine;
use Modules\AI\Services\AiGateway;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantDirectory;
use Throwable;

/**
 * AI inside the chat, through the AI module's provider (docs/chat-tasks.md, phase 3): translate a
 * message, turn a voice message into text, summarise a chat, and suggest replies.
 *
 * Nothing is ever sent to an AI provider on its own: each call is one tap by the person, on a
 * conversation they're in, about messages they can see. View-once messages are never sent. Results
 * are cached (translations and transcripts per message, summaries for a few minutes) so a second
 * tap costs nothing.
 */
class ChatAiService
{
    /** Languages a message can be translated into (code → the name the model understands). */
    public const LANGUAGES = [
        'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German',
        'tr' => 'Turkish', 'ur' => 'Urdu', 'hi' => 'Hindi', 'bn' => 'Bengali', 'id' => 'Indonesian',
        'fa' => 'Persian', 'ru' => 'Russian', 'zh' => 'Chinese', 'pt' => 'Portuguese', 'it' => 'Italian',
        'tl' => 'Filipino',
    ];

    /** Whisper's upload limit. */
    private const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    /** How much of a chat a summary reads (newest first), in messages and characters. */
    private const SUMMARY_MESSAGES = 200;

    private const SUMMARY_CHARS = 12000;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly AiProviderRepository $providers,
        private readonly AiGateway $gateway,
        private readonly SafetyPolicyEngine $safety,
    ) {}

    /**
     * What the app can offer right now (to show or hide the buttons).
     *
     * @return array{enabled: bool, translate: bool, summarize: bool, smart_replies: bool, transcribe: bool}
     */
    public function capabilities(): array
    {
        $on = ChatSetting::current()->aiEnabled();
        $chat = $on && $this->providers->resolveActiveForChat() !== null;
        $audio = $on && $this->providers->resolveForTranscription() !== null;

        return [
            'enabled' => $chat || $audio, 'translate' => $chat, 'summarize' => $chat, 'smart_replies' => $chat, 'transcribe' => $audio, 'ask' => $chat, 'commitments' => $chat,
            // DORR AI tools (spec 31, 36–42, 46, 48, 49).
            'assistant' => $chat, 'proofread' => $chat, 'understand' => $chat, 'simplify' => $chat, 'tasks' => $chat, 'notes' => $chat,
            'dates' => $chat, 'important' => $chat, 'related_files' => $chat, 'today' => $chat,
        ];
    }

    // ---------------------------------------------------------------- translate

    /**
     * @return array{text: string, to: string}
     */
    public function translate(Model $me, ChatMessage $message, string $to): array
    {
        $this->readable($me, $message);
        $text = trim(ChatPushNotifier::plain((string) $message->body));

        if ($text === '') {
            throw new ChatException('ai_nothing_to_translate', 422);
        }

        $key = 'chat:ai:translate:'.$message->id.':'.$to.':'.md5($text);
        $translated = Cache::remember($key, now()->addDays(30), fn () => $this->ask([
            ['role' => 'system', 'content' => 'You translate chat messages. Translate the user\'s message into '.self::LANGUAGES[$to].'. '
                .'Keep the tone, emojis, names, numbers and links as they are. If it is already in '.self::LANGUAGES[$to].', return it unchanged. '
                .'Reply with the translation only — no quotes, no notes.'],
            ['role' => 'user', 'content' => $text],
        ]));

        return ['text' => $translated, 'to' => $to];
    }

    /**
     * A few short fields (a portal's name and description…) from one language into others, in
     * one call. Unknown codes are skipped; a field the model drops comes back empty.
     *
     * @param  array<string, string>  $fields  field => text, in `$from`
     * @param  list<string>  $to
     * @return array<string, array<string, string>> locale => field => text
     */
    public function translateFields(array $fields, string $from, array $to): array
    {
        $to = array_values(array_filter($to, fn ($code) => isset(self::LANGUAGES[$code]) && $code !== $from));
        $fields = array_filter(array_map(fn ($v) => trim((string) $v), $fields), fn ($v) => $v !== '');

        if ($to === [] || $fields === []) {
            return [];
        }

        $targets = implode(', ', array_map(fn ($code) => $code.' ('.self::LANGUAGES[$code].')', $to));
        $source = self::LANGUAGES[$from] ?? 'the original language';

        $raw = $this->ask([
            ['role' => 'system', 'content' => 'You translate short business texts (a shop\'s name and description) from '.$source.' into: '.$targets.'. '
                .'Brand names, links, numbers and emojis stay as they are; a name that is a brand is transliterated only if that is how it is written in that language. '
                .'Reply with JSON only: {"<language code>": {"<field>": "<translation>"}} with the same fields you were given.'],
            ['role' => 'user', 'content' => json_encode($fields, JSON_UNESCAPED_UNICODE)],
        ]);

        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);

        if (! is_array($json)) {
            throw new ChatException('ai_failed', 502);
        }

        $out = [];
        foreach ($to as $code) {
            foreach (array_keys($fields) as $field) {
                $out[$code][$field] = is_string($json[$code][$field] ?? null) ? trim($json[$code][$field]) : '';
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- voice to text

    /**
     * @return array{text: string}
     */
    public function transcribe(Model $me, ChatMessage $message): array
    {
        $this->readable($me, $message);

        if (! in_array($message->type, [MessageType::Voice, MessageType::Audio], true)) {
            throw new ChatException('ai_not_voice', 422);
        }

        $media = $message->getFirstMedia(ChatMessage::ATTACHMENTS) ?? throw new ChatException('ai_not_voice', 422);

        if ($media->size > self::MAX_AUDIO_BYTES) {
            throw new ChatException('ai_audio_too_long', 422);
        }

        $text = Cache::remember('chat:ai:transcribe:'.$message->id, now()->addDays(30), function () use ($media) {
            if (! ChatSetting::current()->aiEnabled() || ($provider = $this->providers->resolveForTranscription()) === null) {
                throw new ChatException('ai_unavailable', 503);
            }

            return $this->answer($provider, $this->gateway->transcribe($provider, $media->getPath(), (string) $media->mime_type));
        });
        // Kept for me, so searching my chats finds what was said (spec 20).
        $participant = $this->conversations->participantOf($me, $message->conversation);
        DB::table('chat_message_transcripts')->updateOrInsert(
            ['message_id' => $message->id, 'participant_id' => $participant->id],
            ['text' => $text, 'updated_at' => now(), 'created_at' => now()],
        );

        return ['text' => $text];
    }

    // ---------------------------------------------------------------- summary

    /**
     * A short summary of the chat as I see it: everything since I last read it (when that's a
     * fair amount), or its latest messages.
     *
     * @return array{text: string, messages: int}
     */
    public function summarize(Model $me, ChatConversation $conversation, bool $unreadOnly = false): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $lines = $this->transcript($me, $participant, $conversation, self::SUMMARY_MESSAGES, $unreadOnly ? $participant->last_read_message_id : null);

        if ($lines->isEmpty()) {
            throw new ChatException('ai_nothing_to_summarize', 422);
        }

        $myName = (string) (app(ParticipantDirectory::class)->profile($me, $participant->participant_type, $participant->participant_id)['account_name'] ?? '');
        $language = self::LANGUAGES[app()->getLocale()] ?? 'English';
        $key = 'chat:ai:summary:'.$conversation->id.':'.$participant->id.':'.$conversation->last_message_id.':'.($unreadOnly ? 'u' : 'a').':'.app()->getLocale();

        $text = Cache::remember($key, now()->addMinutes(10), fn () => $this->ask([
            ['role' => 'system', 'content' => "You summarise a chat for {$myName}, who is \"Me\" in it. Write in {$language}. "
                .'Use 3 to 7 short bullet points: what was discussed, what was decided, questions or requests waiting for Me, and any dates, times, places or amounts. '
                .'Only use what is in the chat — never invent anything. No title, no closing line.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]));

        return ['text' => $text, 'messages' => $lines->count()];
    }

    // ---------------------------------------------------------------- ask about the chat (spec 125)

    /**
     * A question about the chat — about the messages I picked, or the latest ones — answered only
     * from them, with the messages the answer rests on (to jump back to). Only the chosen
     * messages go to the AI (AT-PRIV-11).
     *
     * @param  list<string>|null  $messageIds  the messages I picked (null = the latest)
     * @return array{answer: string, safety: ?array<string, mixed>, references: list<array<string, mixed>>, messages: int}
     */
    public function askAbout(Model $me, ChatConversation $conversation, string $question, ?array $messageIds): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        [$numbered, $lines] = $this->numbered($me, $participant, $conversation, $messageIds);
        $language = self::LANGUAGES[app()->getLocale()] ?? 'English';

        // A free answer: classified and answered under the DORR AI rules (spec 350–362).
        $safe = $this->askSafely([
            ['role' => 'system', 'content' => "You answer a question about a chat, for the person called \"Me\" in it, in {$language}. "
                .'Use only the numbered messages given — if they do not hold the answer, say so plainly. Never invent anything. '
                .'Reply with JSON only: {"answer": "<short answer>", "refs": [<numbers of the messages the answer is based on>]}.'],
            ['role' => 'user', 'content' => $lines->implode("\n")."\n\nQuestion: ".Str::limit(trim($question), 500)],
        ], $question);

        $json = $this->json($safe['raw']);
        $answer = is_string($json['answer'] ?? null) ? $this->safety->finish($json['answer'], $safe['risk'])['text'] : $safe['text'];

        return [
            'answer' => $answer,
            'safety' => $safe['safety'],
            'references' => $this->references($me, $numbered, (array) ($json['refs'] ?? [])),
            'messages' => $numbered->count(),
        ];
    }

    // ---------------------------------------------------------------- commitments (spec 126)

    /**
     * Promises and tasks in the chat ("I'll send the file tomorrow", "can you call the bank?"), as
     * suggestions only: nothing happens until I confirm one (the app then sets a reminder on its
     * message). `timezone` reads "tomorrow at 5" in my own time.
     *
     * @param  list<string>|null  $messageIds
     * @return array{commitments: list<array<string, mixed>>, messages: int}
     */
    public function commitments(Model $me, ChatConversation $conversation, ?array $messageIds, string $timezone): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        [$numbered, $lines] = $this->numbered($me, $participant, $conversation, $messageIds);
        $now = now()->setTimezone($timezone)->format('Y-m-d H:i (l)');

        $raw = $this->ask([
            ['role' => 'system', 'content' => 'You find commitments in a chat: things someone promised to do, or was asked to do and agreed to. '
                .'"Me" is the person you help. It is now '.$now.' in their time zone. '
                .'Reply with a JSON array only (empty if there are none), each item: '
                .'{"text": "<the task, short, in the chat\'s language>", "owner": "me" | "<the other person\'s name>", "due": "YYYY-MM-DD HH:MM" or null, "ref": <number of the message it comes from>}. '
                .'Only what the chat really says; never invent a date.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]);

        $items = $this->json($raw);
        $items = array_is_list($items) ? $items : ($items['commitments'] ?? []);
        $out = [];

        foreach (array_slice((array) $items, 0, 20) as $item) {
            if (! is_array($item) || ! is_string($item['text'] ?? null) || trim($item['text']) === '') {
                continue;
            }
            $message = $numbered->get((int) ($item['ref'] ?? 0));
            $due = null;
            if (is_string($item['due'] ?? null) && $item['due'] !== '') {
                try {
                    $due = Carbon::parse($item['due'], $timezone)->utc();
                } catch (Throwable) {
                    $due = null;
                }
            }
            $owner = is_string($item['owner'] ?? null) ? trim($item['owner']) : '';
            $out[] = [
                'text' => Str::limit(trim($item['text']), 200),
                'owner' => strtolower($owner) === 'me' ? null : ($owner ?: null),
                'is_mine' => strtolower($owner) === 'me',
                'due_at' => $due?->isFuture() ? $due->toIso8601String() : null,
                'message_id' => $message?->uuid,
            ];
        }

        return ['commitments' => $out, 'messages' => $numbered->count()];
    }

    /**
     * The messages the AI may read, numbered 1…n: the ones I picked (only those), or the latest.
     *
     * @param  list<string>|null  $messageIds
     * @return array{0: Collection<int, ChatMessage>, 1: Collection<int, string>}
     */
    public function numbered(Model $me, ChatParticipant $participant, ChatConversation $conversation, ?array $messageIds): array
    {
        $rows = $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereNotNull('sender_type')->whereNull('deleted_for_everyone_at')->where('view_once', false)
            ->when($messageIds !== null, fn ($q) => $q->whereIn('uuid', array_slice($messageIds, 0, 200)))
            ->orderByDesc('id')->limit(self::SUMMARY_MESSAGES)->get()->reverse()->values();

        if ($rows->isEmpty()) {
            throw new ChatException('ai_nothing_to_summarize', 422);
        }

        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->map(fn ($m) => [$m->sender_type, $m->sender_id]));
        $numbered = collect();
        $lines = collect();
        $chars = 0;

        foreach ($rows as $i => $m) {
            $who = $m->isFrom($participant->participant_type, (int) $participant->participant_id)
                ? 'Me'
                : ($directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? '?');
            $what = trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']';
            $line = '['.($i + 1).'] '.$m->created_at?->format('Y-m-d H:i').' '.$who.': '.Str::limit($what, 600);
            $chars += mb_strlen($line);
            if ($chars > self::SUMMARY_CHARS) {
                break;
            }
            $numbered->put($i + 1, $m);
            $lines->push($line);
        }

        return [$numbered, $lines];
    }

    /**
     * @param  Collection<int, ChatMessage>  $numbered
     * @param  array<int, mixed>  $refs
     * @return list<array<string, mixed>>
     */
    public function references(Model $me, Collection $numbered, array $refs): array
    {
        $directory = app(ParticipantDirectory::class);

        return collect($refs)->map(fn ($n) => $numbered->get((int) $n))->filter()->unique('id')->take(10)
            ->map(fn (ChatMessage $m) => [
                'id' => $m->uuid,
                'sender' => $directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? null,
                'excerpt' => Str::limit(trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']', 140),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * The model's JSON (it sometimes wraps it in ``` fences or adds a word around it).
     *
     * @return array<mixed>
     */
    public function json(string $raw): array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw));
        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            $start = strcspn($clean, '[{');
            $decoded = json_decode(substr($clean, $start), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    // ---------------------------------------------------------------- greetings (spec 161)

    /**
     * Three greetings for an occasion, by relation and tone (and dialect), in the request's
     * language — to edit before sending. Nothing about the chat is read.
     *
     * @return array{greetings: list<string>}
     */
    public function greetings(string $occasion, string $relation, string $tone, ?string $name, ?string $dialect): array
    {
        $language = self::LANGUAGES[app()->getLocale()] ?? 'English';
        $raw = $this->ask([
            ['role' => 'system', 'content' => "You write short greeting-card messages in {$language}".($dialect ? " ({$dialect} dialect)" : '').'. '
                .'Write 3 different greetings for the occasion, for the given relationship and tone, each under 40 words, warm and natural, no hashtags. '
                .'Reply with a JSON array of 3 strings and nothing else.'],
            ['role' => 'user', 'content' => json_encode(['occasion' => $occasion, 'relationship' => $relation, 'tone' => $tone, 'name' => $name], JSON_UNESCAPED_UNICODE)],
        ]);

        return ['greetings' => self::parseReplies($raw)];
    }

    // ---------------------------------------------------------------- smart replies

    /**
     * Three short replies I might send next, in the chat's own language. Shown as suggestions —
     * nothing is sent until I pick one and press send.
     *
     * @return array{replies: list<string>}
     */
    public function smartReplies(Model $me, ChatConversation $conversation): array
    {
        $participant = $this->conversations->participantOf($me, $conversation, true);
        $lines = $this->transcript($me, $participant, $conversation, 15);

        if ($lines->isEmpty()) {
            throw new ChatException('ai_nothing_to_summarize', 422);
        }

        $raw = $this->ask([
            ['role' => 'system', 'content' => 'You suggest replies in a chat. "Me" is the person you help. Suggest 3 short, natural replies Me could send next, '
                .'in the same language and tone the chat uses (Arabic dialect stays the same dialect). Each under 12 words, all different. '
                .'Reply with a JSON array of 3 strings and nothing else.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ]);

        return ['replies' => self::parseReplies($raw)];
    }

    /**
     * The model's JSON array — or, when it ignored the format, its lines.
     *
     * @return list<string>
     */
    public static function parseReplies(string $raw): array
    {
        $json = Str::of($raw)->after('[')->beforeLast(']');
        $decoded = json_decode('['.$json.']', true);
        $items = is_array($decoded) ? $decoded : preg_split('/\R/u', $raw);

        return collect($items)
            ->map(fn ($item) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', (string) $item) ?? '', " \t\"'"))
            ->filter(fn (string $item) => $item !== '' && mb_strlen($item) <= 120)
            ->unique()->take(3)->values()->all();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The chat as plain lines ("Me: …", "Sara: …"), oldest first, newest kept when it's long.
     * Only what I can see; no deleted, view-once or system lines.
     *
     * @return Collection<int, string>
     */
    public function transcript(Model $me, ChatParticipant $participant, ChatConversation $conversation, int $limit, ?int $afterId = null): Collection
    {
        $rows = $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereNotNull('sender_type')->whereNull('deleted_for_everyone_at')->where('view_once', false)
            ->when($afterId, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderByDesc('id')->limit($limit)->get();

        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->map(fn ($m) => [$m->sender_type, $m->sender_id]));

        $lines = collect();
        $chars = 0;

        foreach ($rows as $m) {
            $who = $m->isFrom($participant->participant_type, (int) $participant->participant_id)
                ? 'Me'
                : ($directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? '?');
            $what = trim(ChatPushNotifier::plain((string) $m->body));
            if ($what === '') {
                $what = '['.$m->type->value.']';
            }
            $line = $m->created_at?->format('Y-m-d H:i').' '.$who.': '.Str::limit($what, 600);
            $chars += mb_strlen($line);
            if ($chars > self::SUMMARY_CHARS) {
                break;
            }
            $lines->prepend($line);
        }

        return $lines;
    }

    /**
     * A message I'm allowed to send to the AI: in a chat I'm in, visible to me, not deleted, not
     * view-once.
     */
    public function readable(Model $me, ChatMessage $message): void
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);
        $visible = $this->messages->visibleTo($participant, ChatMessage::query()->whereKey($message->id))->exists();

        if (! $visible || $message->isGone()) {
            throw new ChatException('message_not_found', 404);
        }

        if ($message->view_once) {
            throw new ChatException('ai_view_once', 422);
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function ask(array $messages): string
    {
        if (! ChatSetting::current()->aiEnabled() || ($provider = $this->providers->resolveActiveForChat()) === null) {
            throw new ChatException('ai_unavailable', 503);
        }

        return $this->answer($provider, $this->gateway->chat($provider, $messages));
    }

    /**
     * A free answer under the DORR AI rules (spec 350–362): the request is classified first, its
     * rules go in as instructions, and the approved texts come back separately in `safety` (for
     * the alert card). `raw` is the model's own reply (for JSON answers).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, raw: string, safety: ?array<string, mixed>, risk: RiskAssessment}
     */
    public function askSafely(array $messages, string $request): array
    {
        if (! ChatSetting::current()->aiEnabled() || ($provider = $this->providers->resolveActiveForChat()) === null) {
            throw new ChatException('ai_unavailable', 503);
        }

        $risk = $this->safety->classify($request, $provider);
        $raw = $this->answer($provider, $this->gateway->chat($provider, $this->safety->instruct($messages, $risk)));

        return ['raw' => $raw, 'risk' => $risk] + $this->safety->finish($raw, $risk);
    }

    public function safety(): SafetyPolicyEngine
    {
        return $this->safety;
    }

    /**
     * @param  array{success: bool, message: string, content: ?string}  $result
     */
    private function answer(AiProvider $provider, array $result): string
    {
        $text = trim((string) ($result['content'] ?? ''));

        if (! $result['success'] || $text === '') {
            // The provider's own words (keys, quotas…) are for the logs, not for the person.
            Log::warning('[ChatAi] '.$provider->key.': '.$result['message']);

            throw new ChatException('ai_failed', 502);
        }

        return $text;
    }
}
