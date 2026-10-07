<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Enums\AiModelCapability;
use Modules\AI\Support\AiChatLexicon;

/**
 * Figures out which ai_provider_models capabilities a given chat turn
 * actually needs, from two independent signals:
 *
 * 1. The attachment's mime type - an image needs "vision" to be seen at
 *    all (see AiChatService's multimodal payload building), anything else
 *    (pdf/docx/...) needs "document_analysis".
 * 2. Keywords in the message text - the same transparent, extensible
 *    keyword-map trade-off already used by AiIntentClassifier and
 *    AiSafetyGuard, not a full ML classifier.
 *
 * AiRoutingEngine uses the result to prefer a registered ai_provider_models
 * row that actually has the required capability over whichever model a
 * routing rule (or the provider's legacy single $model column) would have
 * picked by default.
 *
 * @see AiRoutingEngine
 */
class AiRequiredCapabilityResolver
{
    /**
     * @var array<string, list<string>>
     */
    protected array $keywordsByCapability = [
        'image_generation' => [
            'ارسم', 'ولد صورة', 'اعمل صورة', 'اعملي صورة', 'صمم لوجو', 'عدل الصورة', 'عدل على الصورة',
            // Real, observed gap: "غيرلي لون الصورة وخليه أحمر" ("change
            // this picture's color to red") - the single most natural way
            // to ask for an image edit in Egyptian Arabic - matched NONE
            // of the phrases above (all built around "عدل"/"edit", never
            // "غير"/"change" or a bare color reference). That silently
            // meant image_generation was never in required_capabilities,
            // so AiChatService::tryHandleImageEdit() never even ran -
            // routing fell through to whichever "vision"-tagged model it
            // could find instead (including stale/deprecated ones), which
            // is exactly the confusing, wrong-model behavior the user hit.
            'لون الصورة', 'لون الصوره', 'غير خلفية', 'شيل الخلفية', 'احذف الخلفية',
            'generate an image', 'draw', 'create an image', 'design a logo', 'edit this image', 'edit the image',
            'change the color', 'change color', 'recolor', 'colorize', 'remove the background',
        ],
        'research' => [
            'ابحث', 'بحث علمي', 'مصادر موثوقة', 'دراسة علمية', 'ورقة بحثية',
            'research', 'cite your sources', 'academic sources', 'scientific study', 'studies show',
        ],
        'study' => [
            'اشرحلي', 'ذاكرلي', 'ساعدني افهم', 'امتحان', 'واجب مدرسي', 'مراجعة',
            'explain this to me', 'help me study', 'help me learn', 'homework', 'exam prep', 'quiz me',
        ],
        'coding' => [
            'اكتب كود', 'دالة', 'فنكشن', 'باج', 'اكواد', 'برمج لي', 'صحح الكود',
            'write code', 'function', 'debug this', 'fix this bug', 'write a script', 'refactor this code',
        ],
        // Master-spec section 13 examples, verbatim: phrases that ask for
        // information that can only be current if it is actually fetched
        // live, not phrases that merely mention the internet in passing
        // ("ابعتلي لينك" doesn't need a search, "آخر أخبار مصر" does).
        'web_search' => [
            'آخر أخبار', 'اخر اخبار', 'سعر الدولار اليوم', 'سعر الدولار النهارده', 'آخر إصدار', 'اخر اصدار',
            'ابحث على الإنترنت', 'ابحث على النت', 'دور على النت', 'إيه الجديد في', 'اخبار اليوم', 'اخبار النهارده',
            'latest news', 'search the web', 'search online', 'current price of', "what's new in", 'whats new in',
            'today\'s exchange rate', 'latest version of', 'search the internet',
        ],
        // Master spec section 47: "the system should be able to request
        // structured output when appropriate". Prompt-level only (an
        // explicit instruction appended to the system prompt - see
        // AiChatService's use of AiModelCapability::StructuredOutput
        // below), not OpenAI's strict response_format=json_schema
        // enforcement - that would need $routing threaded all the way
        // through AiGateway::chat()/every connector the same way
        // $useWebSearch was, which is a materially bigger, riskier change
        // than this resolver entry. Documented as a known limitation, not
        // silently claimed as full support (rule 59).
        'structured_output' => [
            'حول البيانات دي إلى json', 'حوله ل json', 'رجعهالي json', 'بصيغة json', 'شكل json',
            'as json', 'in json format', 'return json', 'return it as json', 'structured output',
            'as a structured output',
        ],
    ];

    /**
     * Verbs commonly used to ask for an image to be changed - checked
     * together with a mention of "the picture" below rather than as
     * standalone keywords, since a bare verb like "غير" ("change"/"other")
     * or "خلي" ("make"/"let") is far too generic on its own.
     *
     * @var list<string>
     */
    protected array $imageChangeVerbs = [
        'غير', 'غيّر', 'بدل', 'بدّل', 'خلي', 'خلّي', 'عدل', 'حول', 'حوّل',
        // Root-cause fix - real, observed bug: "زود كمان على الصوره قطه بتضحك" ("also add a laughing cat to
        // the picture") mentions "الصوره" explicitly - so the word-gate was never
        // the problem - but "زود"/"add" is an ADDITION verb, not a
        // change/recolor one, and this list only ever covered the latter. The
        // result fell straight through to the honest-refusal system message
        // even though the request was mentioning the picture by name.
        'زود', 'زوّد', 'ضيف', 'ضيّف', 'اضف', 'أضف',
        'change', 'recolor', 'colorize', 'add',
    ];

    /**
     * Root-cause fix - real, observed bug: mentionsChangingTheImage()
     * below gives EDIT requests a robust "creation verb + mention of the
     * picture" gate that survives natural phrasing (definite articles,
     * conjugations, ...) instead of needing an exact fixed phrase - but
     * brand-new image GENERATION only ever had the rigid
     * $keywordsByCapability['image_generation'] phrase list, which is a
     * byte-exact substring check. A message like "اعمل الصوره اللى
     * طلبتها منك" ("make the picture I asked you for") never matches the
     * fixed phrase "اعمل صورة"/"اعمل صوره" because of the inserted "ال"
     * (definite article) - "اعمل" + "الصوره" is not the same substring as
     * "اعمل" + "صوره". The request silently carried no required
     * capabilities, so routing picked an ordinary chat model, which then
     * (correctly, for itself) explained it cannot actually produce an
     * image - confusing and wrong from the user's point of view, since a
     * real image-generation model/connector exists and was never even
     * considered. These verbs mirror $imageChangeVerbs but for CREATING a
     * picture from scratch rather than changing an existing one.
     *
     * @var list<string>
     */
    protected array $imageCreationVerbs = [
        'اعمل', 'أعمل', 'اعملي', 'أعملي', 'اطلع', 'أطلع', 'طلعلي', 'ولد', 'وّلد', 'اصنع', 'أصنع', 'جهزلي',
        'make', 'generate', 'create',
    ];

    /**
     * Verbs used to ASK FOR code to be written, checked together with a
     * bare mention of "code" below - the same verb+mention gate already
     * used for image edits above, for the same reason: a literal phrase
     * list ('اكتب كود', 'برمج لي', ...) only ever matches that exact
     * wording and silently misses every natural conjugation ("تكتبلي
     * كود", "تكتبلى كود") of the same request.
     *
     * @var list<string>
     */
    protected array $codeCreationVerbs = [
        'تكتب', 'أكتب', 'اكتب', 'يكتب', 'اعمل', 'أعمل', 'اعملي', 'ابعت', 'ابعتلي', 'ابنيلي', 'ابني',
        'صمم', 'برمج', 'جهز', 'جهزلي',
        'write', 'create', 'build', 'generate',
    ];

    /**
     * @return list<string>
     */
    public function resolve(string $content, ?string $attachmentMimeType = null, bool $recentImageExists = false): array
    {
        $required = [];
        // Root-cause fix (systemic): every keyword/verb gate below used to
        // do a byte-exact comparison, so each Arabic spelling variant
        // (ى/ي, ة/ه, hamza forms, diacritics, elongation) and each dialect
        // (Egyptian vs Saudi/Gulf vs MSA vs English) needed its own hand
        // added entry - and every miss silently routed the request to a
        // plain chat model. All matching now goes through
        // AiChatLexicon/ArabicTextNormalizer, which normalizes BOTH the
        // message and every phrase, so one natural spelling per phrase
        // covers them all. The legacy phrase lists below are kept and
        // consulted too, so nothing that matched before can stop matching.
        $legacy = array_map(
            fn (array $keywords) => $keywords,
            $this->keywordsByCapability,
        );

        foreach ($legacy as $capability => $keywords) {
            if (AiChatLexicon::containsAny($content, $keywords)) {
                $required[] = $capability;
            }
        }

        $viaLexicon = [
            'image_generation' => AiChatLexicon::wantsImageGeneration($content) || AiChatLexicon::wantsImageEdit($content, $recentImageExists),
            'video_output' => AiChatLexicon::wantsVideoGeneration($content),
            'research' => AiChatLexicon::wantsResearch($content),
            'study' => AiChatLexicon::wantsStudyHelp($content),
            'coding' => AiChatLexicon::wantsCode($content),
            'web_search' => AiChatLexicon::wantsWebSearch($content),
            'structured_output' => AiChatLexicon::wantsStructuredOutput($content),
        ];

        foreach ($viaLexicon as $capability => $matched) {
            if ($matched) {
                $required[] = $capability;
            }
        }

        if ($attachmentMimeType !== null) {
            if (str_starts_with($attachmentMimeType, 'image/')) {
                // Root-cause fix - real, observed bug: this used to
                // unconditionally add "vision" for ANY attached image,
                // even when the message is really an edit/generate
                // request ("عدل الصوره وخليها باللون الاحمر"). Combined
                // with "image_generation" from the keyword/verb check
                // above, that demanded a SINGLE registered model tagged
                // with BOTH "vision" AND "image_generation" at once - an
                // impossible combination by this platform's own design:
                // AiProviderModelSyncService deliberately registers a real
                // image-edit/generation model (gpt-image-1, etc.) with
                // ONLY "image_generation", never "chat" or "vision",
                // because that endpoint genuinely cannot answer a normal
                // chat/completions call at all. Requiring both meant
                // bestModelFor() could never match anything, however
                // correctly the admin's models were registered, and every
                // edit request honestly (but wrongly) reported "I can't
                // edit images" - editing sends the attached file's raw
                // bytes directly to a dedicated image-edit endpoint
                // (AiChatService::tryHandleImageEdit()), it never needs
                // the model to "see" it conversationally first. "vision"
                // is now only added when there is no edit/generate intent
                // - a plain "اشرحلي اللي في الصورة" still correctly
                // requires a vision-capable model as before.
                if (! in_array(AiModelCapability::ImageGeneration->value, $required, true)) {
                    $required[] = AiModelCapability::Vision->value;
                }
            } elseif (str_starts_with($attachmentMimeType, 'audio/')) {
                // Root-cause fix - the same class of bug as the vision/
                // image_generation conjunction above: a voice message is
                // NOT a document, so it must never fall into the "else"
                // branch and demand "document_analysis" on the model that
                // is about to draft the actual chat reply. Transcription
                // is handled entirely separately, before this resolver
                // ever runs, by AiChatService::transcribeIncomingAudio()
                // picking its own dedicated speech_to_text-capable model
                // and replacing the attachment with the transcript text -
                // by the time required_capabilities is computed for THIS
                // request, the "attachment" is already plain text. Adding
                // a capability requirement here would force the routing
                // engine to pick a transcription-only model (never tagged
                // "chat") to answer the user's actual question with,
                // which cannot work - the exact "not a chat model" trap
                // already root-caused once for defaultRegisteredModel().
            } else {
                $required[] = AiModelCapability::DocumentAnalysis->value;
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * Real, observed gap: the single most natural way to ask for an image
     * edit in Egyptian Arabic - "غيرلي لون الصورة وخليه أحمر" ("change
     * this picture's color to red"), or a bare follow-up like "خليها لون
     * احمر" ("make it red") - freely attaches pronoun suffixes ("غيرلي",
     * "خليها", "خليه") that break a literal multi-word phrase match like
     * "غير لون" or "خلي لون" even though the intent is identical. Rather
     * than trying to enumerate every conjugation, this requires BOTH a
     * change verb AND a mention of "the picture"/"صورة" anywhere in the
     * message - precise enough to stay safe (an unrelated question like
     * "ايه أفضل لون للموقع بتاعي؟" never mentions a picture at all, so it
     * is never misrouted to the image-editing model) while still covering
     * the real conjugations that broke before.
     */
    protected function mentionsChangingTheImage(string $lower, bool $recentImageExists = false): bool
    {
        // Root-cause fix - real, observed bug: a user who was just shown
        // a generated picture replying "خلي الطفل ده لابس بدلة وفى فرح
        // كده ومعاه بوكيه ورد" ("make this kid wear a suit...") never
        // says the word "صورة"/"image"/"picture" at all - they are
        // naturally talking about the SUBJECT of the photo they can see
        // right above the composer, not "the picture" as an abstract
        // object. The old $mentionsPicture gate required that literal
        // word unconditionally, so this read as a normal chat message
        // and the plain text model answered "sorry, I can't edit images"
        // - true for it personally, but dishonest about what this app
        // can actually do. $recentImageExists (true only when the
        // immediately preceding message in this conversation carried an
        // image - see AiChatService::conversationEndsWithImage()) lets a
        // bare change-verb stand in for the missing "the picture" ONLY
        // in that narrow, unambiguous situation; anywhere else in the
        // conversation the literal-word requirement still applies, so an
        // unrelated "خليك هادي" days later in the same thread is not
        // affected.
        $mentionsPicture = $recentImageExists || Str::contains($lower, ['صور', 'image', 'picture', 'photo']);

        if (! $mentionsPicture) {
            return false;
        }

        foreach ($this->imageChangeVerbs as $verb) {
            if ($this->containsWordStartingWith($lower, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same verb+mention gate as mentionsChangingTheImage() above, but for
     * a brand-new image GENERATION request rather than an edit of an
     * existing one - see $imageCreationVerbs' docblock for the real,
     * observed bug this fixes. Deliberately does not consult
     * $recentImageExists the way the edit gate does: a bare creation verb
     * right after a generated image ("اعمل واحده تانية" with no mention
     * of "صورة" at all) is ambiguous between "make another picture" and
     * an unrelated request, and misreading it the wrong way silently
     * routes to the image model for a normal chat message - a worse
     * failure than asking the user to be explicit once.
     */
    protected function mentionsGeneratingImage(string $lower): bool
    {
        if (! Str::contains($lower, ['صور', 'image', 'picture', 'photo', 'logo', 'لوجو'])) {
            return false;
        }

        foreach ($this->imageCreationVerbs as $verb) {
            if ($this->containsWordStartingWith($lower, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same verb+mention gate as mentionsChangingTheImage() above, for a
     * request to write code: requires BOTH a creation verb AND a mention
     * of "code"/a code fence, so an unrelated "عايز كود خصم" ("I want a
     * discount code") - no creation verb present - is never misrouted to
     * a coding-capable model, while "تكتبلي كود لصفحة منتجات" correctly
     * is.
     */
    protected function mentionsWritingCode(string $lower): bool
    {
        $mentionsCode = Str::contains($lower, ['كود', 'اكواد', 'كواد', 'code', 'script', '```']);

        if (! $mentionsCode) {
            return false;
        }

        foreach ($this->codeCreationVerbs as $verb) {
            if ($this->containsWordStartingWith($lower, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Real, observed gap (mirrors AiChatService::containsWordStartingWith(),
     * the same fix applied where the same bug lived a second time): plain
     * Str::contains($lower, 'غير') also matches a message like "اصنع صوره
     * فيها طفل صغير" - "صغير" (small) happens to END with the exact same
     * three letters as "غير" (change), so a raw substring check
     * incorrectly required image_generation for a brand new "make a
     * picture of a small child" request. This still matches a verb
     * followed by an attached pronoun suffix ("خليها" = "خلي" + "ها") -
     * only checked is that the verb is NOT itself glued onto the END of
     * some other, unrelated word.
     */
    protected function containsWordStartingWith(string $haystack, string $needle): bool
    {
        // Root-cause fix - same real, observed bug and same reasoning as
        // AiChatService::containsWordStartingWith(): "خلى" (alef maksura)
        // vs "خلي" (ya) are typed interchangeably in everyday Egyptian
        // Arabic and never matched each other as distinct Unicode
        // codepoints, so a message like "خلى كمان واقف فى ميدان" never
        // set required_capabilities=[image_generation] at all, and the
        // router picked an ordinary chat model instead of an image-edit
        // one.
        $haystack = str_replace('ى', 'ي', $haystack);

        $pattern = '/(?<![\p{L}\p{M}])'.preg_quote(mb_strtolower($needle), '/').'/u';

        return (bool) preg_match($pattern, $haystack);
    }
}
