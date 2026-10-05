<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantDirectory;

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

        return ['enabled' => $chat || $audio, 'translate' => $chat, 'summarize' => $chat, 'smart_replies' => $chat, 'transcribe' => $audio];
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
    private function transcript(Model $me, ChatParticipant $participant, ChatConversation $conversation, int $limit, ?int $afterId = null): Collection
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
    private function readable(Model $me, ChatMessage $message): void
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
    private function ask(array $messages): string
    {
        if (! ChatSetting::current()->aiEnabled() || ($provider = $this->providers->resolveActiveForChat()) === null) {
            throw new ChatException('ai_unavailable', 503);
        }

        return $this->answer($provider, $this->gateway->chat($provider, $messages));
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
