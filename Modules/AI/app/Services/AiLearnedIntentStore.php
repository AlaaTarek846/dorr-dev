<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\AiChatLexicon;
use Modules\AI\Support\AiIntentVerdictParser;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * Read/write access to the learned half of the intent dictionary
 * (ai_learned_intents). See that migration for the big picture.
 *
 * Two kinds of entries, deliberately different in how much they are
 * trusted:
 *  - exact: the WHOLE (short, PII-free) message equals the stored text.
 *    Cannot misfire on any other message, so it is active immediately.
 *  - phrase: a trigger phrase found INSIDE messages ("تخيل لي"). Broader,
 *    so it only becomes active after the model confirmed it more than
 *    once with the same intent and never contradicted itself.
 */
class AiLearnedIntentStore
{
    private const CACHE_KEY = 'ai:learned_intents:active:v1';

    private const EXACT_MAX_CHARS = 60;

    private const EXACT_MAX_TOKENS = 7;

    public function __construct(protected AiPiiSanitizer $sanitizer) {}

    /**
     * @return array{id: int, intent: string, file_format: ?string}|null
     */
    public function match(string $normalizedMessage, bool $recordHit = true): ?array
    {
        if ($normalizedMessage === '') {
            return null;
        }

        foreach ($this->activeRows() as $row) {
            $hit = $row['match_mode'] === AiLearnedIntent::MODE_EXACT
                ? $row['phrase'] === $normalizedMessage
                : AiChatLexicon::containsPhraseNormalized($normalizedMessage, $row['phrase']);

            if (! $hit) {
                continue;
            }

            // "No voice" style negations still win over a learned voice phrase.
            if ($row['intent'] === AiChatIntent::VOICE_REPLY
                && AiChatLexicon::containsAnyNormalized($normalizedMessage, AiChatLexicon::VOICE_NEGATIONS)) {
                continue;
            }

            if ($recordHit) {
                $this->recordHit($row['id']);
            }

            return ['id' => $row['id'], 'intent' => $row['intent'], 'file_format' => $row['file_format']];
        }

        return null;
    }

    /**
     * Remember what the model just decided, if it is safe to.
     *
     * @param  array{intents: list<string>, confidence: float, file_format: ?string, trigger_phrase: ?string}  $verdict
     */
    public function learn(string $normalizedMessage, array $verdict, ?string $modelKey): void
    {
        // A multi-intent request cannot be stored as one phrase -> one intent.
        if (count($verdict['intents']) !== 1) {
            return;
        }

        if ($verdict['confidence'] < (float) config('ai.intent_router.learn_min_confidence', 0.9)) {
            return;
        }

        if (AiLearnedIntent::query()->count() >= (int) config('ai.intent_router.max_learned_rows', 5000)) {
            Log::warning('ai_intent_router.learned_table_full');

            return;
        }

        $intent = $verdict['intents'][0];
        $format = $intent === AiChatIntent::FILE_OUTPUT ? $verdict['file_format'] : null;
        $language = AiIntentVerdictParser::languageOf($normalizedMessage);

        try {
            if ($this->isSafeForExact($normalizedMessage)) {
                $this->upsert($normalizedMessage, AiLearnedIntent::MODE_EXACT, $intent, $format, $language, $verdict['confidence'], $modelKey);
            }

            $phrase = AiIntentVerdictParser::learnablePhrase($verdict['trigger_phrase'], $normalizedMessage);

            if ($phrase !== null && $phrase !== $normalizedMessage) {
                $this->upsert($phrase, AiLearnedIntent::MODE_PHRASE, $intent, $format, $language, $verdict['confidence'], $modelKey);
            }
        } catch (\Throwable $e) {
            // Learning is an optimisation - never let it break a chat turn.
            report($e);
        }
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Short, single-sentence, PII-free messages only: the stored text is
     * the user's own words, so anything personal is simply not learned.
     */
    protected function isSafeForExact(string $normalizedMessage): bool
    {
        if (mb_strlen($normalizedMessage) > self::EXACT_MAX_CHARS) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $normalizedMessage, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < 2 || count($tokens) > self::EXACT_MAX_TOKENS) {
            return false;
        }

        if (preg_match('/\d{3,}|@|https?:|www\./u', $normalizedMessage) === 1) {
            return false;
        }

        return $this->sanitizer->redactBoth($normalizedMessage) === $normalizedMessage;
    }

    protected function upsert(string $phrase, string $mode, string $intent, ?string $format, string $language, float $confidence, ?string $modelKey): void
    {
        $minConfirmations = max(1, (int) config('ai.intent_router.learn_min_confirmations', 2));

        DB::transaction(function () use ($phrase, $mode, $intent, $format, $language, $confidence, $modelKey, $minConfirmations) {
            $conflicting = AiLearnedIntent::query()
                ->where('phrase', $phrase)
                ->where('match_mode', $mode)
                ->where('intent', '!=', $intent)
                ->lockForUpdate()
                ->get();

            // The model disagrees with its own earlier answer about the
            // same words: stop trusting them until an admin looks.
            if ($conflicting->isNotEmpty()) {
                foreach ($conflicting as $row) {
                    $row->conflicts++;
                    $row->is_active = false;
                    $row->save();
                }

                $this->flushCache();

                return;
            }

            $row = AiLearnedIntent::query()
                ->where('phrase', $phrase)
                ->where('match_mode', $mode)
                ->where('intent', $intent)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                AiLearnedIntent::query()->create([
                    'phrase' => $phrase,
                    'match_mode' => $mode,
                    'intent' => $intent,
                    'file_format' => $format,
                    'language' => $language,
                    'confidence' => $confidence,
                    'confirmations' => 1,
                    'is_active' => $mode === AiLearnedIntent::MODE_EXACT || $minConfirmations <= 1,
                    'source' => AiLearnedIntent::SOURCE_MODEL,
                    'learned_with_model' => $modelKey,
                ]);
            } else {
                $row->confirmations++;
                $row->confidence = max((float) $row->confidence, $confidence);
                $row->file_format ??= $format;

                // Never auto-reactivate something the model contradicted
                // or an admin switched off (the admin command marks it
                // with conflicts > 0 for exactly this reason).
                if ($row->conflicts === 0 && $row->confirmations >= $minConfirmations) {
                    $row->is_active = true;
                }

                $row->save();
            }

            $this->flushCache();
        });
    }

    protected function recordHit(int $id): void
    {
        try {
            AiLearnedIntent::query()->whereKey($id)->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<array{id: int, phrase: string, match_mode: string, intent: string, file_format: ?string}>
     */
    protected function activeRows(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('ai.intent_router.active_cache_seconds', 300),
            function (): array {
                return AiLearnedIntent::query()
                    ->where('is_active', true)
                    ->orderByDesc('hits')
                    ->limit(2000)
                    ->get(['id', 'phrase', 'match_mode', 'intent', 'file_format'])
                    ->map(fn (AiLearnedIntent $row) => [
                        'id' => $row->id,
                        'phrase' => ArabicTextNormalizer::normalize($row->phrase),
                        'match_mode' => $row->match_mode,
                        'intent' => $row->intent,
                        'file_format' => $row->file_format,
                    ])
                    // Longest phrase first, so the most specific entry wins.
                    ->sortByDesc(fn (array $row) => mb_strlen($row['phrase']))
                    ->values()
                    ->all();
            },
        );
    }
}
