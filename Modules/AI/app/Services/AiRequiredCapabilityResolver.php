<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Enums\AiModelCapability;

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
        'change', 'recolor', 'colorize',
    ];

    /**
     * @return list<string>
     */
    public function resolve(string $content, ?string $attachmentMimeType = null): array
    {
        $required = [];
        $lower = mb_strtolower($content);

        foreach ($this->keywordsByCapability as $capability => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($lower, mb_strtolower($keyword))) {
                    $required[] = $capability;
                    break;
                }
            }
        }

        if (! in_array(AiModelCapability::ImageGeneration->value, $required, true) && $this->mentionsChangingTheImage($lower)) {
            $required[] = AiModelCapability::ImageGeneration->value;
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
    protected function mentionsChangingTheImage(string $lower): bool
    {
        $mentionsPicture = Str::contains($lower, ['صور', 'image', 'picture', 'photo']);

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
        $pattern = '/(?<![\p{L}\p{M}])'.preg_quote(mb_strtolower($needle), '/').'/u';

        return (bool) preg_match($pattern, $haystack);
    }
}
