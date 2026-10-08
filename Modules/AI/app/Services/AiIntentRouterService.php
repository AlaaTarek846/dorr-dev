<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\AiChatLexicon;
use Modules\AI\Support\AiIntentDecision;
use Modules\AI\Support\AiIntentVerdictParser;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * "Step 2" of understanding what a user wants, for the messages the
 * static dictionary (AiChatLexicon) could not classify:
 *
 *   1. lexicon  - free, instant. If it already recognises the request,
 *                 nothing else runs and no extra flags are needed.
 *   2. learned  - free, instant. Phrases this router learned earlier
 *                 (ai_learned_intents) from the default model.
 *   3. model    - ONE short call to the DEFAULT provider/model, asked to
 *                 classify the message into the closed AiChatIntent
 *                 vocabulary as strict JSON. The answer is validated
 *                 (AiIntentVerdictParser), thresholded, and - when the
 *                 model is confident - the words that expressed the
 *                 request are stored so the same kind of message never
 *                 costs a model call again.
 *
 * Safety properties (this runs in front of every chat turn, so it must
 * never be the reason a chat fails, slows down for long, or does
 * something the user did not ask for):
 *  - every failure path returns "no decision" and chat continues exactly
 *    as it did before this class existed;
 *  - the model only SUGGESTS: output outside the closed vocabulary or
 *    below the confidence threshold is ignored;
 *  - per-user rate limit, a local circuit breaker (stops asking after
 *    repeated failures), negative caching of "this is just chat", and
 *    skipping of greetings / very short / very long messages;
 *  - the message is PII-redacted and truncated before it leaves, and no
 *    message text is written to logs.
 */
class AiIntentRouterService
{
    /** Messages that are plain social chat - never worth a model call. */
    private const SMALL_TALK = [
        'مرحبا', 'اهلا', 'اهلا وسهلا', 'هلا', 'هلا والله', 'السلام عليكم', 'وعليكم السلام', 'صباح الخير', 'مساء الخير', 'ازيك', 'عامل ايه',
        'اخبارك', 'شلونك', 'كيفك', 'كيف حالك', 'شكرا', 'شكرا ليك', 'شكرا لك', 'مشكور', 'تسلم', 'تسلم ايدك', 'يعطيك العافيه', 'تمام', 'اوك',
        'اوكي', 'ماشي', 'حاضر', 'طيب', 'لا', 'ايوه', 'نعم', 'اه', 'hi', 'hello', 'hey', 'yo', 'thanks', 'thank you', 'thx', 'ok', 'okay',
        'good morning', 'good evening', 'good night', 'bye', 'مع السلامه', 'باي',
    ];

    public function __construct(
        protected AiLearnedIntentStore $learned,
        protected AiGateway $gateway,
        protected AiModelResolver $modelResolver,
        protected AiCircuitBreaker $circuitBreaker,
        protected AiPiiSanitizer $sanitizer,
    ) {}

    public function decide(
        Authenticatable $owner,
        string $content,
        bool $hasAttachment,
        bool $recentImageExists,
        AiProviderRepository $providers,
        bool $hasImageContext = false,
    ): AiIntentDecision {
        if (! (bool) config('ai.intent_router.enabled', true)) {
            return AiIntentDecision::none();
        }

        $normalized = ArabicTextNormalizer::normalize($content);

        // 1. The built-in dictionary understood it: nothing more to do.
        if ($this->lexiconUnderstands($content, $recentImageExists)) {
            return AiIntentDecision::none('lexicon');
        }

        if ($this->isNotWorthClassifying($normalized)) {
            return AiIntentDecision::none();
        }

        // 2. Something this router learned earlier.
        try {
            $hit = $this->learned->match($normalized);
        } catch (\Throwable $e) {
            report($e);
            $hit = null;
        }

        if ($hit !== null) {
            $decision = $this->decisionFromLearned($hit, $hasImageContext || $recentImageExists);

            if ($decision !== null) {
                return $decision;
            }
        }

        // 3. Ask the default model - behind every guard.
        $negativeKey = 'ai:intent_router:chat:'.sha1($normalized);

        if (Cache::has($negativeKey)) {
            return AiIntentDecision::none();
        }

        if ($this->circuitIsOpen()) {
            return AiIntentDecision::none();
        }

        $rateKey = 'ai-intent-router:'.$owner->getMorphClass().':'.$owner->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($rateKey, (int) config('ai.intent_router.max_calls_per_minute', 20))) {
            return AiIntentDecision::none();
        }

        RateLimiter::hit($rateKey, 60);

        $target = $this->resolveTarget($providers);

        if ($target === null) {
            return AiIntentDecision::none();
        }

        $hasImageContext = $hasImageContext || $recentImageExists;

        $verdict = $this->askModel($target['provider'], $content, $hasAttachment, $hasImageContext);

        if ($verdict === null) {
            $this->recordFailure();

            return AiIntentDecision::none();
        }

        $this->resetFailures();

        $minConfidence = (float) config('ai.intent_router.min_confidence', 0.7);

        if ($verdict['intents'] === [] || $verdict['confidence'] < $minConfidence) {
            // Plain conversation (or not sure enough): remember that, so
            // the same message does not ask again for a while.
            Cache::put($negativeKey, 1, now()->addMinutes((int) config('ai.intent_router.negative_cache_minutes', 1440)));

            Log::info('ai_intent_router.decision', ['source' => 'model', 'intents' => [], 'confidence' => $verdict['confidence']]);

            return AiIntentDecision::none('model');
        }

        $this->learned->learn($normalized, $verdict, $target['model_key']);

        Log::info('ai_intent_router.decision', [
            'source' => 'model',
            'intents' => $verdict['intents'],
            'confidence' => $verdict['confidence'],
            'file_format' => $verdict['file_format'],
        ]);

        return new AiIntentDecision($verdict['intents'], $verdict['file_format'], 'model', $verdict['confidence']);
    }

    /**
     * Which intents the built-in dictionary recognises in a message.
     * Mirrors exactly what AiRequiredCapabilityResolver/AiChatService
     * already act on, so "the lexicon understood it" is never contradicted
     * downstream.
     *
     * @return list<string>
     */
    public function lexiconIntents(string $content, bool $recentImageExists = false): array
    {
        $found = [];

        $checks = [
            AiChatIntent::VOICE_REPLY => AiChatLexicon::wantsVoiceReply($content),
            AiChatIntent::FILE_OUTPUT => AiChatLexicon::wantsFileOutput($content),
            AiChatIntent::IMAGE_GENERATION => AiChatLexicon::wantsImageGeneration($content),
            AiChatIntent::IMAGE_EDIT => AiChatLexicon::wantsImageEdit($content, $recentImageExists),
            AiChatIntent::VIDEO_GENERATION => AiChatLexicon::wantsVideoGeneration($content),
            AiChatIntent::WEB_SEARCH => AiChatLexicon::wantsWebSearch($content),
            AiChatIntent::RESEARCH => AiChatLexicon::wantsResearch($content),
            AiChatIntent::STUDY => AiChatLexicon::wantsStudyHelp($content),
            AiChatIntent::CODING => AiChatLexicon::wantsCode($content),
            AiChatIntent::STRUCTURED_OUTPUT => AiChatLexicon::wantsStructuredOutput($content),
        ];

        foreach ($checks as $intent => $matched) {
            if ($matched) {
                $found[] = $intent;
            }
        }

        return $found;
    }

    protected function lexiconUnderstands(string $content, bool $recentImageExists): bool
    {
        return $this->lexiconIntents($content, $recentImageExists) !== [];
    }

    /**
     * What WOULD happen to this message - for the admin "test a phrase"
     * panel. No model call, no hit counted, nothing learned.
     *
     * @return array{stage: string, intents: list<string>, file_format: ?string, learned_id: ?int, normalized: string, router_enabled: bool}
     */
    public function preview(string $content, bool $recentImageExists = false): array
    {
        $normalized = ArabicTextNormalizer::normalize($content);
        $enabled = (bool) config('ai.intent_router.enabled', true);

        $base = ['intents' => [], 'file_format' => null, 'learned_id' => null, 'normalized' => $normalized, 'router_enabled' => $enabled];

        $lexicon = $this->lexiconIntents($content, $recentImageExists);

        if ($lexicon !== []) {
            return ['stage' => 'lexicon', 'intents' => $lexicon, 'file_format' => in_array(AiChatIntent::FILE_OUTPUT, $lexicon, true) ? AiChatLexicon::detectRequestedFileFormat($content) : null] + $base;
        }

        if ($this->isNotWorthClassifying($normalized)) {
            return ['stage' => 'skipped'] + $base;
        }

        $hit = $this->learned->match($normalized, false);

        if ($hit !== null) {
            return ['stage' => 'learned', 'intents' => [$hit['intent']], 'file_format' => $hit['file_format'], 'learned_id' => $hit['id']] + $base;
        }

        return ['stage' => $enabled ? 'would_ask_model' : 'router_disabled'] + $base;
    }

    protected function isNotWorthClassifying(string $normalized): bool
    {
        $length = mb_strlen($normalized);

        if ($length < (int) config('ai.intent_router.min_message_chars', 6)) {
            return true;
        }

        // A very long message is a pasted document / essay, not a command.
        if ($length > (int) config('ai.intent_router.max_message_chars', 600)) {
            return true;
        }

        if (preg_match('/\p{L}/u', $normalized) !== 1) {
            return true;
        }

        $smallTalk = array_map([ArabicTextNormalizer::class, 'normalize'], self::SMALL_TALK);

        return in_array($normalized, $smallTalk, true);
    }

    /**
     * @param  array{id: int, intent: string, file_format: ?string}  $hit
     */
    protected function decisionFromLearned(array $hit, bool $hasImageContext): ?AiIntentDecision
    {
        $intent = $hit['intent'];

        if (! in_array($intent, AiChatIntent::ACTIONS, true)) {
            return null;
        }

        // Same rule as for a fresh model answer: an edit needs an image.
        if ($intent === AiChatIntent::IMAGE_EDIT && ! $hasImageContext) {
            $intent = AiChatIntent::IMAGE_GENERATION;
        }

        Log::info('ai_intent_router.decision', ['source' => 'learned', 'intents' => [$intent], 'learned_id' => $hit['id']]);

        return new AiIntentDecision([$intent], $hit['file_format'], 'learned', 1.0);
    }

    /**
     * The DEFAULT provider and its default model - the same choice the
     * admin already made for plain chat - unless an explicit router model
     * is configured. Never a dedicated image/audio-only model.
     *
     * @return array{provider: AiProvider, model_key: string}|null
     */
    protected function resolveTarget(AiProviderRepository $providers): ?array
    {
        $provider = $providers->resolveActiveForChat();

        if ($provider === null) {
            $fallback = $this->modelResolver->resolve($providers);
            $provider = $fallback['provider'] ?? null;
        }

        if ($provider === null || $this->circuitBreaker->isOpen($provider)) {
            return null;
        }

        $modelKey = config('ai.intent_router.model')
            ?: $provider->model
            ?: $provider->defaultRegisteredModel()?->model_key;

        if (! $modelKey) {
            return null;
        }

        return [
            'provider' => tap(clone $provider, fn (AiProvider $p) => $p->model = $modelKey),
            'model_key' => $modelKey,
        ];
    }

    /**
     * @return array{intents: list<string>, confidence: float, file_format: ?string, trigger_phrase: ?string}|null
     */
    protected function askModel(AiProvider $provider, string $content, bool $hasAttachment, bool $hasImageContext): ?array
    {
        $message = $this->sanitizer->redactBoth(
            mb_substr(trim($content), 0, (int) config('ai.intent_router.max_message_chars', 600)),
        );

        $userPrompt = "Message: \"\"\"\n{$message}\n\"\"\"\n"
            .'The user attached a file or image: '.($hasAttachment ? 'yes' : 'no')."\n"
            .'There is an image in the conversation right now that could be edited: '.($hasImageContext ? 'yes' : 'no')."\n"
            .'Return the JSON now.';

        try {
            $result = $this->gateway->chat($provider, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $userPrompt],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! ($result['success'] ?? false) || blank($result['content'] ?? null)) {
            return null;
        }

        return AiIntentVerdictParser::parse((string) $result['content'], $hasImageContext);
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the intent classifier of a chat assistant. Decide what the user's message asks the assistant to DO, in any language or dialect (Egyptian Arabic, Saudi/Gulf Arabic, Modern Standard Arabic, English, or a mix, with any spelling).

Allowed intents (use ONLY these exact words):
- "image_generation": the user wants a NEW picture/logo/poster/illustration created. ("تخيل لي بيت على البحر", "سويلي شعار لمحلي", "show me what a dragon looks like")
- "image_edit": the user wants an EXISTING image changed (recolor, remove background, add or remove something, restyle). ("خلي الخلفية بيضا", "شيلها من الصورة", "make it look like a painting")
- "video_generation": the user wants a NEW short video clip created. ("اعمللي فيديو لقطة بتجري", "make a video of waves on a beach")
- "voice_reply": the user wants the assistant's answer delivered as spoken audio / a voice note. ("قولهالي بصوتك", "ابغى اسمعها", "read it to me")
- "file_output": the user wants the result delivered as a downloadable FILE (PDF, Word, Excel). Reading or summarising a file they attached is NOT file_output.
- "web_search": the answer needs current, live information from the internet (news, prices, scores, weather, latest versions).
- "research": the user wants in-depth, sourced research or an academic-style report.
- "study": the user wants to learn, understand a lesson, solve homework or be quizzed.
- "coding": the user wants program code written, fixed or explained.
- "structured_output": the user wants the answer as JSON / a strict data structure.
- "chat": none of the above - normal conversation, a question, advice, opinion, translation, writing text in the chat.

Rules:
- Choose "chat" unless the user CLEARLY asks for one of the actions. When unsure, choose "chat".
- A question ABOUT an image, file or code (what is this, explain this) is NOT image_generation / file_output / coding creation.
- You may return several intents when the message clearly asks for several (e.g. web_search + file_output).
- "confidence" is your honest certainty from 0 to 1.
- "file_format" is "pdf", "docx" or "xlsx" only when "file_output" is chosen and the format is clear, otherwise null.
- "trigger_phrase" is the 2 to 6 words COPIED EXACTLY from the message that express the request (never invent words, never include names, numbers or private details), or null if no such short phrase exists or the intent is "chat".

Respond with ONLY one JSON object, no markdown, no commentary:
{"intents": ["..."], "confidence": 0.0, "file_format": null, "trigger_phrase": null}
PROMPT;
    }

    protected function circuitIsOpen(): bool
    {
        return (int) Cache::get('ai:intent_router:failures', 0) >= (int) config('ai.intent_router.failure_threshold', 3);
    }

    protected function recordFailure(): void
    {
        $key = 'ai:intent_router:failures';

        Cache::add($key, 0, now()->addSeconds((int) config('ai.intent_router.failure_cooldown_seconds', 300)));
        Cache::increment($key);

        Log::warning('ai_intent_router.model_failed', ['failures' => (int) Cache::get($key, 0)]);
    }

    protected function resetFailures(): void
    {
        Cache::forget('ai:intent_router:failures');
    }
}
