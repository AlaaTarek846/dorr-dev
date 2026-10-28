<?php

namespace Modules\Chat\Services;

use Illuminate\Contracts\Auth\Authenticatable;
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
use Modules\AI\Services\AiCitationValidator;
use Modules\AI\Services\AiEvidencePlanner;
use Modules\AI\Services\AiMemoryService;
use Modules\AI\Services\AiTaskRunner;
use Modules\AI\Services\AiVerificationEngine;
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
 *
 * Every call goes through the AI module's AiTaskRunner — the same system as the DORR AI chat: the
 * admin's AI block, safety rules, routing (provider/model per policy and country), fallback,
 * circuit breaker, the registered speech-to-text model, and the usage log (`source` chat.*).
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
        private readonly AiTaskRunner $runner,
        private readonly SafetyPolicyEngine $safety,
        private readonly AiVerificationEngine $verifier,
        private readonly AiEvidencePlanner $evidence,
        private readonly AiCitationValidator $citations,
        private readonly AiMemoryService $memory,
    ) {}

    /**
     * What the app can offer right now (to show or hide the buttons).
     *
     * @return array{enabled: bool, translate: bool, summarize: bool, smart_replies: bool, transcribe: bool}
     */
    public function capabilities(?Model $me = null): array
    {
        // The admin's switch, and any country or city rule for where I am (ChatFeatures).
        $on = app(ChatFeatures::class)->allows($me, 'ai');
        $chat = $on && $this->runner->available();
        $audio = $on && $this->runner->canTranscribe();
        $images = $on && $this->runner->canMakeImages();
        $owner = $this->owner($me);

        return [
            'enabled' => $chat || $audio, 'translate' => $chat, 'summarize' => $chat, 'smart_replies' => $chat, 'transcribe' => $audio, 'ask' => $chat, 'commitments' => $chat,
            // DORR AI tools (spec 31, 36–42, 46, 48, 49).
            'assistant' => $chat, 'proofread' => $chat, 'understand' => $chat, 'simplify' => $chat, 'tasks' => $chat, 'notes' => $chat,
            'dates' => $chat, 'important' => $chat, 'related_files' => $chat, 'today' => $chat,
            // Images from a description and AI image editing (spec 50, 51).
            'image_generate' => $images, 'image_edit' => $images,
            // DORR AI's search by meaning, memory, continue-in-another-chat and AI-images gallery, in the chat.
            'search_meaning' => $chat, 'memory' => $chat, 'capsule' => $chat, 'ai_images' => $on,
            // Every AI tool needs a DORR AI plan (decision 2026-10-15): when `allowed` is false the
            // app keeps the buttons but locks them and offers the AI subscription (`reason` says why).
            'plan' => $owner !== null ? $this->runner->entitlement($owner) : ['allowed' => false, 'reason' => 'no_plan'],
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
        ], 'chat.translate', $me));

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
        ], 'chat.translate_fields');

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

        $text = Cache::remember('chat:ai:transcribe:'.$message->id, now()->addDays(30), function () use ($me, $media) {
            if (! ChatSetting::current()->aiEnabled()) {
                throw new ChatException('ai_unavailable', 503);
            }

            return $this->answer($this->runner->transcribe($media->getPath(), (string) $media->mime_type, 'chat.transcribe', $this->owner($me)));
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
        ], 'chat.summary', $me));

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
        ], $question, verify: true, source: 'chat.ask_about', me: $me);

        $json = $this->json($safe['raw']);
        $answer = is_string($json['answer'] ?? null) ? $this->safety->finish($json['answer'], $safe['risk'])['text'] : $safe['text'];

        return [
            'answer' => $answer,
            'safety' => $safe['safety'],
            'verification' => $safe['verification'],
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
        ], 'chat.commitments', $me);

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
        ], 'chat.greetings');

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

        // Suggested replies are new text, so they are classified and written under the DORR AI rules
        // (spec ب.2, 351–362): a medical, legal or religious chat never gets a "yes, double the dose"
        // suggestion. No referral is added — the person sends these as their own words.
        $risk = $this->safety->classify((string) $lines->last(), $this->providers->resolveActiveForChat());
        $raw = $this->ask($this->safety->instruct(array_values(array_filter([
            ['role' => 'system', 'content' => 'You suggest replies in a chat. "Me" is the person you help. Suggest 3 short, natural replies Me could send next, '
                .'in the same language and tone the chat uses (Arabic dialect stays the same dialect). Each under 12 words, all different. '
                .'Reply with a JSON array of 3 strings and nothing else.'],
            // What I asked DORR AI to remember about me (I'm vegetarian, I don't work Fridays…).
            ($memory = $this->memoryFor($me)) !== null ? ['role' => 'system', 'content' => $memory] : null,
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ])), $risk), 'chat.smart_replies', $me);

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

    // ---------------------------------------------------------------- memory (DORR AI spec 140–144, in the chat)

    /**
     * My DORR AI memory for a chat AI tool: one memory per person, shared with the DORR AI chat
     * (AiMemoryService). [$asked] starting with «افتكر إن…» / "remember that…" is saved first (when
     * memory is on), and the tool is told so it can confirm. Null when memory is off or empty.
     */
    public function memoryFor(Model $me, ?string $asked = null): ?string
    {
        $owner = $this->owner($me);
        if ($owner === null) {
            return null;
        }

        $note = null;
        if ($asked !== null && ($fact = $this->memory->explicitRequest($asked)) !== null) {
            try {
                $this->memory->remember($owner, $fact);
                $note = 'The person asked you to remember: "'.$fact.'". It has been saved to their DORR AI memory (they can see, edit or delete it). Confirm this briefly.';
            } catch (\Modules\AI\Exceptions\AiAssistantException $e) {
                $note = 'The person asked you to remember something, but it was NOT saved: '.__('ai.assistant_'.$e->reason).' Tell them briefly.';
            }
        }

        return collect([$note, $this->memory->systemMessage($owner)])->filter()->implode("\n\n") ?: null;
    }

    // ---------------------------------------------------------------- search by meaning (DORR AI spec 133–139, in the chat)

    /**
     * Search this chat by what the words mean: DORR AI widens the query into related words —
     * synonyms, the dialect and the other language («الكورة» → «الماتش», «كرة القدم», «football») —
     * and the chat's own search (only messages I can see) runs for each. One AI call, on the plan.
     *
     * @return array{terms: list<string>, messages: list<mixed>, matches: array<string, string>}
     */
    public function searchByMeaning(Model $me, ChatConversation $conversation, string $query): array
    {
        $this->conversations->participantOf($me, $conversation);
        $query = Str::limit(trim($query), 100, '');

        $terms = Cache::remember('chat:ai:meaning:'.md5(mb_strtolower($query)).':'.app()->getLocale(), now()->addDay(), function () use ($me, $query) {
            $raw = $this->ask([
                ['role' => 'system', 'content' => 'You help search a chat by meaning. For the search query, give up to 8 short search words or phrases that '
                    .'messages about the same thing would contain: synonyms, the formal and the colloquial/dialect forms, related key words, and the English or Arabic equivalent. '
                    .'One to three words each. Reply with a JSON array of strings only.'],
                ['role' => 'user', 'content' => $query],
            ], 'chat.search_meaning', $me);

            return collect($this->json($raw))
                ->filter(fn ($t) => is_string($t) && mb_strlen(trim($t)) >= 2 && mb_strlen(trim($t)) <= 40)
                ->map(fn (string $t) => trim($t))
                ->reject(fn (string $t) => mb_strtolower($t) === mb_strtolower($query))
                ->unique()->take(8)->values()->all();
        });

        // The words themselves first, then what they mean; each message once, with the word that found it.
        $found = collect();
        $matches = [];
        foreach ([$query, ...$terms] as $term) {
            foreach ($this->messages->search($me, $term, $conversation) as $resource) {
                $id = (string) $resource->resource->uuid;
                if (! isset($matches[$id])) {
                    $matches[$id] = $term;
                    $found->push($resource);
                }
            }
            if ($found->count() >= 100) {
                break;
            }
        }

        return ['terms' => $terms, 'messages' => $found->take(100)->values()->all(), 'matches' => $matches];
    }

    // ---------------------------------------------------------------- continue in another chat (DORR AI spec 121–132, in the chat)

    /**
     * A Context Capsule of this chat — its goal, decisions, important facts, files, open tasks and
     * what was done — from the messages I picked (only those) or the latest, to carry the work on in
     * another chat. Nothing is sent: the app shows it, I can edit it, and I choose where it goes.
     *
     * @param  list<string>|null  $messageIds
     * @return array{capsule: array<string, mixed>, text: string, messages: int}
     */
    public function capsule(Model $me, ChatConversation $conversation, ?array $messageIds): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        [$numbered, $lines] = $this->numbered($me, $participant, $conversation, $messageIds);
        $language = self::LANGUAGES[app()->getLocale()] ?? 'English';

        $json = $this->json($this->ask([
            ['role' => 'system', 'content' => 'You prepare a "context capsule" so a person can carry on the work of a chat in another chat without starting over. '
                .'"Me" is the person you help. Write in '.$language.'. Reply with JSON only: '
                .'{"goal": "<what this chat is trying to get done, one sentence>", "decisions": [<decisions taken>], "facts": [<important facts, numbers, names, dates, places, amounts>], '
                .'"open_tasks": [<what is still left to do, and by whom when said>], "done": [<what was already done>]}. '
                .'Short items. Only what the messages really say — never invent anything; an empty list when there is nothing.'],
            ['role' => 'user', 'content' => $lines->implode("\n")],
        ], 'chat.capsule', $me));

        $list = fn (string $key) => collect((array) ($json[$key] ?? []))->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn (string $v) => Str::limit(trim($v), 300))->take(15)->values()->all();
        $capsule = [
            'goal' => is_string($json['goal'] ?? null) ? Str::limit(trim($json['goal']), 300) : null,
            'decisions' => $list('decisions'),
            'facts' => $list('facts'),
            'open_tasks' => $list('open_tasks'),
            'done' => $list('done'),
            // File names from the messages themselves, not from the model.
            'files' => $numbered->filter(fn (ChatMessage $m) => in_array($m->type, [MessageType::Document, MessageType::Image, MessageType::Video], true))
                ->map(fn (ChatMessage $m) => $m->getFirstMedia(ChatMessage::ATTACHMENTS)?->file_name)->filter()->unique()->take(20)->values()->all(),
        ];

        return ['capsule' => $capsule, 'text' => $this->capsuleText($capsule), 'messages' => $numbered->count()];
    }

    /**
     * The capsule as a message I can edit and send — headings in my language.
     *
     * @param  array<string, mixed>  $capsule
     */
    private function capsuleText(array $capsule): string
    {
        $parts = ['📌 '.__('chat.capsule.title')];
        if (filled($capsule['goal'])) {
            $parts[] = '*'.__('chat.capsule.goal').':* '.$capsule['goal'];
        }
        foreach (['decisions', 'facts', 'files', 'open_tasks', 'done'] as $key) {
            if (! empty($capsule[$key])) {
                $parts[] = '*'.__('chat.capsule.'.$key).':*'."\n- ".implode("\n- ", $capsule[$key]);
            }
        }

        return implode("\n\n", $parts);
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
     * One AI call through DORR AI's own system (AiTaskRunner). [$source] names the tool on the
     * admin usage screens; [$me] is who it is for (the signed-in person when not given).
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function ask(array $messages, string $source = 'chat', ?Model $me = null): string
    {
        if (! ChatSetting::current()->aiEnabled()) {
            throw new ChatException('ai_unavailable', 503);
        }

        return $this->answer($this->runner->chat($messages, $source, $this->owner($me)));
    }

    /**
     * A free answer under the DORR AI rules (spec 350–362): the request is classified first, its
     * rules go in as instructions, and the approved texts come back separately in `safety` (for
     * the alert card). `raw` is the model's own reply (for JSON answers). [$verify] runs it
     * through the quality gate too (see qualityGate()).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, raw: string, safety: ?array<string, mixed>, risk: RiskAssessment, verification: ?array{status: string, confidence: ?float, notice: ?string}}
     */
    public function askSafely(array $messages, string $request, bool $verify = false, string $source = 'chat', ?Model $me = null): array
    {
        if (! ChatSetting::current()->aiEnabled() || ! $this->runner->available()) {
            throw new ChatException('ai_unavailable', 503);
        }

        $owner = $this->owner($me);
        $risk = $this->safety->classify($request, $this->providers->resolveActiveForChat());
        // DORR AI's evidence rules for this request's topics (EvidencePlanner, spec 324), after the
        // safety rules — the chat inherits them like the DORR AI chat (spec ب.2).
        $instructed = $this->safety->instruct($messages, $risk);
        array_splice($instructed, 1, 0, [['role' => 'system', 'content' => $this->evidence->guidance($request)]]);
        $result = $this->runner->chat($instructed, $source, $owner);
        $raw = $this->answer($result);
        $verification = null;
        if ($verify) {
            [$raw, $verification] = $this->qualityGate($result['provider'], $instructed, $raw, $source, $owner);
            // What the system checks itself (CitationValidator, spec 332): Qur'an references,
            // arithmetic, dates, jurisdiction — shown with the quality gate's verdict.
            $warnings = $this->citations->validate($this->answerOf($raw), $request)['warnings'];
            if ($verification !== null || $warnings !== []) {
                $verification = ($verification ?? ['status' => 'skipped', 'confidence' => null, 'notice' => null]) + ['warnings' => $warnings];
            }
        }

        return ['raw' => $raw, 'risk' => $risk, 'verification' => $verification] + $this->safety->finish($raw, $risk);
    }

    /**
     * The DORR AI quality gate (spec 362), which the chat inherits (spec ب.2): DORR AI's own
     * verifier checks the draft — another provider when there is one, so two models catch each
     * other's mistakes. Below the pass mark the draft goes back once with what was found; still
     * weak, it is shown with a "check it" notice, or held back when confidence is very low. The
     * AI module's own switch and thresholds apply (`ai.chat.verification.*`).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{0: string, 1: ?array{status: string, confidence: ?float, notice: ?string}}
     */
    private function qualityGate(AiProvider $provider, array $messages, string $raw, string $source, ?Authenticatable $owner): array
    {
        if (! config('ai.chat.verification.enabled', true)) {
            return [$raw, null];
        }

        $checker = $this->providers->all()->first(fn (AiProvider $p) => $p->id !== $provider->id && $p->isUsableForChat()) ?? $provider;
        $question = (string) (collect($messages)->last(fn (array $m) => $m['role'] === 'user')['content'] ?? '');
        $attempts = max(1, (int) config('ai.chat.verification.max_attempts', 2));
        $pass = (float) config('ai.chat.verification.pass_threshold', 0.6);
        $abstain = (float) config('ai.chat.verification.abstain_threshold', 0.35);
        $confidence = 0.0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $verdict = $this->verifier->verify($checker, $question, $this->answerOf($raw));
            if ($verdict['engine_error']) {
                return [$raw, ['status' => 'skipped', 'confidence' => null, 'notice' => null]];
            }
            $confidence = (float) $verdict['confidence_score'];
            if ($confidence >= $pass && $verdict['issues'] === []) {
                return [$raw, ['status' => 'passed', 'confidence' => $confidence, 'notice' => null]];
            }
            if ($attempt === $attempts) {
                break;
            }
            // Back once, with what the check found.
            $issues = $verdict['issues'] ?: array_values(array_filter(array_map(
                fn (array $c) => $c['supported'] ? null : 'Unsupported: '.$c['claim'],
                $verdict['claims'],
            )));
            try {
                $raw = $this->answer($this->runner->chat([
                    ...$messages,
                    ['role' => 'assistant', 'content' => $raw],
                    ['role' => 'user', 'content' => "A fact-checker reviewed your answer and found:\n- ".implode("\n- ", $issues ?: ['Some claims could not be verified.'])
                        ."\nCorrect it. Do not state anything you cannot support. Keep exactly the same reply format."],
                ], $source.'.correction', $owner));
            } catch (ChatException) {
                break;
            }
        }

        if ($confidence < $abstain) {
            return [$this->withAnswer($raw, __('ai.verification_abstain_reply')), ['status' => 'abstained', 'confidence' => $confidence, 'notice' => null]];
        }

        return [$raw, ['status' => 'unverified', 'confidence' => $confidence, 'notice' => __('ai.verification_low_confidence_notice')]];
    }

    /** The answer itself: the `answer` of a JSON reply, or the reply. */
    private function answerOf(string $raw): string
    {
        $answer = $this->json($raw)['answer'] ?? null;

        return is_string($answer) ? $answer : $raw;
    }

    /** The same reply with its answer replaced (and nothing referenced any more). */
    private function withAnswer(string $raw, string $answer): string
    {
        $json = $this->json($raw);
        if (! is_string($json['answer'] ?? null)) {
            return $answer;
        }

        return (string) json_encode(['answer' => $answer, 'refs' => []], JSON_UNESCAPED_UNICODE);
    }

    public function safety(): SafetyPolicyEngine
    {
        return $this->safety;
    }

    /** Who an AI call is for: [$me], or whoever is signed in (null in a background job). */
    private function owner(?Model $me): ?Authenticatable
    {
        $owner = $me ?? request()?->user();

        return $owner instanceof Authenticatable ? $owner : null;
    }

    /**
     * AiTaskRunner's result as text, or the chat error the person sees.
     *
     * @param  array{success: bool, reason: ?string, content: ?string, message: ?string, provider: ?AiProvider}  $result
     */
    private function answer(array $result): string
    {
        $text = trim((string) ($result['content'] ?? ''));

        if ($result['success'] && $text !== '') {
            return $text;
        }

        throw $this->failure($result);
    }

    /**
     * The chat error for a refused or failed AI task. A plan refusal is `ai_plan_{reason}` (402)
     * with `reason` in the data — the app locks the tool and offers the AI subscription.
     *
     * @param  array{reason: ?string, message: ?string, provider: ?AiProvider, detail?: array<string, mixed>}  $result
     */
    public function failure(array $result): ChatException
    {
        $detail = $result['detail'] ?? [];

        return match ($result['reason']) {
            AiTaskRunner::REASON_OWNER_BLOCKED => new ChatException('ai_blocked', 403),
            AiTaskRunner::REASON_CONTENT_BLOCKED => new ChatException('ai_message_blocked', 422),
            AiTaskRunner::REASON_UNAVAILABLE => new ChatException('ai_unavailable', 503),
            AiTaskRunner::REASON_PLAN => new ChatException('ai_plan_'.($detail['usage_reason'] ?? 'no_plan'), 402, [], [
                'reason' => $detail['usage_reason'] ?? 'no_plan',
                'cooldown_seconds_left' => $detail['cooldown_seconds_left'] ?? null,
            ]),
            AiTaskRunner::REASON_IMAGE_NOT_IN_PLAN => new ChatException('ai_image_not_in_plan', 402, [], ['reason' => 'image_not_in_plan']),
            AiTaskRunner::REASON_IMAGE_LIMIT => new ChatException('ai_image_daily_limit', 429, ['limit' => $detail['limit'] ?? 0], ['reason' => 'image_daily_limit', 'limit' => $detail['limit'] ?? 0]),
            default => (function () use ($result) {
                // The provider's own words (keys, quotas…) are for the logs, not for the person.
                Log::warning('[ChatAi] '.($result['provider']?->key ?? '-').': '.$result['message']);

                return new ChatException('ai_failed', 502);
            })(),
        };
    }
}
