<?php

namespace Modules\AI\Support;

/**
 * Pure, framework-free validation of what the intent-router model sends
 * back. The model's answer is UNTRUSTED input: it is parsed defensively,
 * every field is checked against a closed vocabulary, and anything
 * unexpected is dropped rather than acted on. Kept free of Laravel so it
 * can be unit-tested without a database or a provider.
 */
final class AiIntentVerdictParser
{
    private const MIN_PHRASE_TOKENS = 2;

    private const MAX_PHRASE_TOKENS = 6;

    private const MIN_PHRASE_CHARS = 4;

    private const MAX_PHRASE_CHARS = 60;

    /** Words that carry no intent on their own. */
    private const STOPWORDS = [
        'انا', 'انت', 'انتا', 'انتي', 'ممكن', 'لو', 'سمحت', 'من', 'في', 'فى', 'على', 'علي', 'عن', 'الي', 'الى', 'ده', 'دي', 'دا',
        'هذا', 'هذه', 'يا', 'لي', 'لى', 'ليا', 'نفسي', 'بدي', 'هل', 'ما', 'ماذا', 'كده', 'كدا', 'بس', 'طيب', 'تمام', 'اوك', 'يلا',
        'لما', 'اللي', 'او', 'ثم', 'كمان', 'تاني', 'برضه', 'هو', 'هي', 'معايا', 'معاي', 'عشان', 'علشان', 'لازم', 'محتاج', 'محتاجه',
        'عايز', 'عاوز', 'عايزه', 'عاوزه', 'ابغى', 'ابي', 'اريد', 'وش', 'ايه', 'ايش', 'شو', 'شنو', 'مع', 'بعد', 'قبل', 'ان', 'انه',
        'the', 'a', 'an', 'to', 'of', 'me', 'my', 'i', 'you', 'your', 'can', 'could', 'would', 'will', 'please', 'pls', 'want', 'need',
        'like', 'it', 'this', 'that', 'these', 'those', 'for', 'and', 'or', 'is', 'are', 'do', 'does', 'with', 'in', 'on', 'at', 'be',
        'just', 'so', 'now', 'then', 'some', 'something', 'about', 'from', 'by',
        // Question words: "show me what" / "قولي كيف" say nothing about the action.
        'what', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'how', 'there', 'here', 'than',
        'كيف', 'ليه', 'ليش', 'لماذا', 'ازاي', 'امتى', 'متى', 'فين', 'اين', 'مين', 'من', 'كام', 'كم', 'ايمتى', 'وين', 'منو',
    ];

    /** Verbs too common/generic to carry an intent without their object. */
    private const GENERIC_VERBS = [
        'give', 'make', 'show', 'tell', 'send', 'get', 'let', 'put', 'take', 'do', 'have',
        'اعمل', 'اعملي', 'اعملى', 'هات', 'هاتلي', 'جيب', 'جيبلي', 'قول', 'قولي', 'قوللي', 'ابعت', 'ابعتلي', 'وريني', 'ورني',
        'اكتب', 'اكتبلي', 'سوي', 'سويلي', 'خلي', 'خلى', 'ارسل', 'اعطني', 'عطني', 'اعطيني', 'عطيني',
    ];

    /**
     * @return array{intents: list<string>, confidence: float, file_format: ?string, trigger_phrase: ?string}|null
     */
    public static function parse(string $raw, bool $hasImageContext): ?array
    {
        $raw = trim($raw);

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $data = json_decode(substr($raw, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            return null;
        }

        $rawIntents = $data['intents'] ?? ($data['intent'] ?? []);
        $rawIntents = is_array($rawIntents) ? $rawIntents : [$rawIntents];

        $intents = [];

        foreach ($rawIntents as $intent) {
            if (! is_string($intent)) {
                continue;
            }

            $intent = strtolower(trim($intent));

            if (in_array($intent, AiChatIntent::ACTIONS, true)) {
                $intents[] = $intent;
            }
        }

        $intents = array_values(array_unique($intents));

        // An image edit only makes sense when there IS an image to edit
        // (attached, or just shown). Otherwise it is a new image request.
        if (in_array(AiChatIntent::IMAGE_EDIT, $intents, true)) {
            $intents = array_values(array_filter($intents, fn ($i) => $i !== AiChatIntent::IMAGE_GENERATION));

            if (! $hasImageContext) {
                $intents = array_values(array_map(
                    fn ($i) => $i === AiChatIntent::IMAGE_EDIT ? AiChatIntent::IMAGE_GENERATION : $i,
                    $intents,
                ));
                $intents = array_values(array_unique($intents));
            }
        }

        $confidence = $data['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0;

        $format = null;

        if (in_array(AiChatIntent::FILE_OUTPUT, $intents, true) && is_string($data['file_format'] ?? null)) {
            $format = strtolower(ltrim(trim($data['file_format']), '.'));
            $format = match ($format) {
                'word', 'doc' => 'docx',
                'excel', 'xls', 'csv' => 'xlsx',
                default => $format,
            };

            if (! in_array($format, AiChatIntent::FILE_FORMATS, true)) {
                $format = null;
            }
        }

        $phrase = is_string($data['trigger_phrase'] ?? null) ? $data['trigger_phrase'] : null;

        return [
            'intents' => $intents,
            'confidence' => $confidence,
            'file_format' => $format,
            'trigger_phrase' => $phrase,
        ];
    }

    /**
     * Whether the model's `trigger_phrase` is safe to remember as a
     * dictionary entry. Returns the NORMALIZED phrase, or null.
     *
     * Strict on purpose - a too-generic phrase ("عايز", "give me") would
     * misfire on thousands of unrelated messages once learned:
     *  - it must literally appear, word-aligned, in the user's message
     *    (the model cannot invent a phrase);
     *  - 2-6 words, 4-60 characters, no digits/e-mails/links;
     *  - at least one real content word (not a stopword and not a bare
     *    generic verb) of 4+ characters.
     */
    public static function learnablePhrase(?string $phrase, string $normalizedMessage): ?string
    {
        if ($phrase === null) {
            return null;
        }

        $phrase = ArabicTextNormalizer::normalize($phrase);

        $length = mb_strlen($phrase);

        if ($length < self::MIN_PHRASE_CHARS || $length > self::MAX_PHRASE_CHARS) {
            return null;
        }

        if (preg_match('/\d|@|https?:|www\./u', $phrase) === 1) {
            return null;
        }

        $tokens = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < self::MIN_PHRASE_TOKENS || count($tokens) > self::MAX_PHRASE_TOKENS) {
            return null;
        }

        $quoted = preg_quote($phrase, '/');

        if (preg_match('/(?<![\p{L}\p{M}\p{N}])'.$quoted.'(?![\p{L}\p{M}\p{N}])/u', $normalizedMessage) !== 1) {
            return null;
        }

        $ignored = array_map([ArabicTextNormalizer::class, 'normalize'], array_merge(self::STOPWORDS, self::GENERIC_VERBS));

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 4 && ! in_array($token, $ignored, true)) {
                return $phrase;
            }
        }

        return null;
    }

    /** 'ar' | 'en' | 'mixed' - informational, used to organise learned rows. */
    public static function languageOf(string $text): string
    {
        $arabic = preg_match('/\p{Arabic}/u', $text) === 1;
        $latin = preg_match('/[a-z]/i', $text) === 1;

        return match (true) {
            $arabic && $latin => 'mixed',
            $arabic => 'ar',
            default => 'en',
        };
    }
}
