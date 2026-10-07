<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Services\AiRequiredCapabilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Real, observed bug: a user asking to edit a picture the most natural
 * way in Egyptian Arabic - "غيرلي لون الصورة وخليه أحمر" ("change this
 * picture's color to red") - matched none of the original image_generation
 * keywords, which were all built around "عدل"/"edit". That silently kept
 * image_generation out of required_capabilities, so
 * AiChatService::tryHandleImageEdit() never even ran and routing fell back
 * to whichever "vision"-tagged model it could find - including stale,
 * deprecated, or wrongly-tagged ones - instead of the real image-editing
 * model. Pure PHP, no DB/framework needed.
 */
class AiRequiredCapabilityResolverTest extends TestCase
{
    protected function resolver(): AiRequiredCapabilityResolver
    {
        return new AiRequiredCapabilityResolver;
    }

    public function test_changing_a_pictures_color_in_egyptian_arabic_is_recognized_as_image_generation(): void
    {
        $required = $this->resolver()->resolve('غيرلي لون الصورة وخليه أحمر', 'image/png');

        $this->assertContains('image_generation', $required);
    }

    public function test_a_bare_follow_up_asking_to_change_the_color_is_recognized_with_no_new_attachment(): void
    {
        $required = $this->resolver()->resolve('غير لون الصوره', null);

        $this->assertContains('image_generation', $required);
    }

    public function test_change_the_color_in_english_is_recognized(): void
    {
        $required = $this->resolver()->resolve('change the color of this image to red', 'image/png');

        $this->assertContains('image_generation', $required);
    }

    /**
     * The exact real-world message that exposed this bug: the pronoun
     * suffix on "خليها" ("make it") breaks a literal "خلي لون" phrase
     * match, but the change-verb + picture-mention heuristic still
     * catches it correctly.
     */
    public function test_a_pronoun_suffixed_verb_is_still_recognized(): void
    {
        $required = $this->resolver()->resolve('ادى الصوره خليها لون احمر', null);

        $this->assertContains('image_generation', $required);
    }

    public function test_a_website_color_question_with_no_picture_mention_is_not_misrouted(): void
    {
        $required = $this->resolver()->resolve('ايه أفضل لون للموقع بتاعي؟', null);

        $this->assertNotContains('image_generation', $required);
    }

    public function test_an_unrelated_message_does_not_falsely_require_image_generation(): void
    {
        $required = $this->resolver()->resolve('ايه رأيك في الطقس النهارده؟', null);

        $this->assertNotContains('image_generation', $required);
    }

    /**
     * Real, observed gap: "صغير" (small) ends with the exact same three
     * letters as "غير" (the "change" edit-verb trigger), so a plain
     * substring check misread "make a picture of a small child" - a
     * brand NEW image request, no attachment - as an edit of an existing
     * one. Fixed in AiRequiredCapabilityResolver::containsWordStartingWith()
     * (mirrored in AiChatService for the exact same false positive in
     * resolveSourceImageBytes()).
     */
    public function test_a_word_that_merely_ends_with_an_edit_verbs_letters_is_not_a_false_positive(): void
    {
        // A brand-new "make a picture of a small child" request IS image
        // generation (the dialect-aware lexicon covers it), but "صغير"
        // ending in the letters of the edit verb "غير" must never make
        // it read as an EDIT of an existing image.
        $required = $this->resolver()->resolve('اصنع صوره فيها طفل صغير', null);

        $this->assertContains('image_generation', $required);
        $this->assertFalse(\Modules\AI\Support\AiChatLexicon::wantsImageEdit('اصنع صوره فيها طفل صغير'));
    }

    public function test_an_attached_image_with_no_edit_wording_still_only_requires_vision(): void
    {
        $required = $this->resolver()->resolve('اشرحلي اللي في الصورة دي', 'image/png');

        $this->assertContains('vision', $required);
        $this->assertNotContains('image_generation', $required);
    }

    /**
     * Phase 5 (doc S14) disclosure, NOT a fix: this resolver's image
     * branch is unconditional - ANY image attachment requires `vision`,
     * even for a purely metadata question that RasterImageFileProcessor
     * can now answer honestly from getimagesize()/EXIF alone with no
     * model call at all. Distinguishing "what are this image's
     * dimensions?" from "what's in this image?" at the
     * capability-resolution level is a real, pre-existing gap in this
     * OLD attachment pipeline (AiChatService/AiRequiredCapabilityResolver),
     * unrelated to and unchanged by the new AiFileEngine image
     * processors - same "disclose, don't silently fix the old pipeline"
     * choice already made and documented in the Phase 4 Final Report.
     * This test exists to PIN today's actual behavior so a future phase
     * that does add that distinction has a failing test to update, not
     * to assert that today's behavior is correct.
     */
    public function test_a_metadata_only_image_question_still_unconditionally_requires_vision_today(): void
    {
        $required = $this->resolver()->resolve('ما هي أبعاد هذه الصورة؟', 'image/png');

        $this->assertContains('vision', $required);
    }

    /**
     * Real, observed bug (the exact message that exposed it): "خلى كمان
     * واقف فى ميدان وجنبيه جنود تانيين" uses alef maksura ("ى") instead
     * of ya ("ي") in "خلى" - both are typed completely interchangeably in
     * everyday Egyptian Arabic, especially on mobile keyboards, but
     * $imageChangeVerbs only ever listed the ya spelling ("خلي"/"خلّي"),
     * a different Unicode codepoint. A bare follow-up edit right after
     * the assistant had just returned a generated image (recentImageExists
     * = true) silently fell through to a plain chat model instead of
     * reaching the real image-edit path, so the user got an unrelated
     * clarifying-questions reply instead of an actual edited photo.
     */
    public function test_a_followup_edit_verb_spelled_with_alef_maksura_is_still_recognized(): void
    {
        $required = $this->resolver()->resolve('خلى كمان واقف فى ميدان وجنبيه جنود تانيين', null, recentImageExists: true);

        $this->assertContains('image_generation', $required);
    }

    /**
     * Root-cause fix - real, observed bug: the user's exact real message
     * ("عدل الصوره وخليها باللون الاحمر" with the image freshly attached)
     * always got an honest but WRONG "I can't edit images" refusal, even
     * right after registering a real image-edit model correctly under
     * "image_generation". Cause: attaching an image used to
     * unconditionally add "vision" on top of "image_generation" - forcing
     * AiProvider::bestModelFor() to find a SINGLE registered model tagged
     * with BOTH at once, which this platform's own registration rules
     * (AiProviderModelSyncService) make structurally impossible - a real
     * image-edit model is deliberately tagged "image_generation" ONLY,
     * never "vision" or "chat", because it cannot answer a chat/completions
     * call at all. This proves an edit/generate request with a freshly
     * attached image now requires ONLY "image_generation", never "vision"
     * alongside it - editing sends the attachment's raw bytes straight to
     * the dedicated image-edit endpoint, it never needs the model to see
     * it conversationally first.
     */
    public function test_editing_a_freshly_attached_image_requires_only_image_generation_not_vision_too(): void
    {
        $required = $this->resolver()->resolve('عدل الصوره وخليها باللون الاحمر', 'image/png');

        $this->assertContains('image_generation', $required);
        $this->assertNotContains('vision', $required);
    }

    /**
     * Root-cause fix - the same class of bug as the vision/image_generation
     * conjunction above, for the newly-added voice feature: an attached
     * voice message must NEVER fall into the generic "else -> document
     * analysis" branch. AiChatService::transcribeIncomingAudio() already
     * turns the attachment into plain text BEFORE this resolver ever runs
     * for the real per-turn routing decision, using its own dedicated
     * speech_to_text-capable model - requiring "document_analysis" here
     * would force the model that drafts the actual chat reply to also be
     * tagged for document analysis, for no reason (the "attachment" is
     * already gone by then), and would misroute plain voice messages away
     * from whichever model would otherwise have answered them best.
     */
    public function test_an_audio_attachment_requires_no_capability_at_all_not_document_analysis(): void
    {
        $required = $this->resolver()->resolve('', 'audio/mpeg');

        $this->assertNotContains('document_analysis', $required);
        $this->assertSame([], $required);
    }

    public function test_an_audio_attachment_with_accompanying_text_still_resolves_normally(): void
    {
        $required = $this->resolver()->resolve('اكتب كود لدالة جمع رقمين', 'audio/mpeg');

        $this->assertContains('coding', $required);
        $this->assertNotContains('document_analysis', $required);
    }

    /**
     * Master-spec section 13, verbatim Arabic examples: phrases that only
     * make sense if answered with genuinely current information.
     */
    public function test_asking_for_todays_exchange_rate_requires_web_search(): void
    {
        $required = $this->resolver()->resolve('سعر الدولار اليوم كام؟', null);

        $this->assertContains('web_search', $required);
    }

    public function test_asking_for_latest_news_in_english_requires_web_search(): void
    {
        $required = $this->resolver()->resolve('give me the latest news about OpenAI', null);

        $this->assertContains('web_search', $required);
    }

    public function test_an_ordinary_question_does_not_falsely_require_web_search(): void
    {
        $required = $this->resolver()->resolve('إيه الفرق بين الـ interface والـ abstract class؟', null);

        $this->assertNotContains('web_search', $required);
    }

    /**
     * Real, observed bug (the exact message that exposed it): "ممكن
     * تكتبلى كود لصفحه منتجات" - the extremely natural "[can] you write
     * me code for a products page" phrasing - matched none of the fixed
     * phrases in $keywordsByCapability['coding'], which are all built
     * around the bare imperative "اكتب" ("write"), never "تكتبلي"/
     * "تكتبلى" ("[can/will] you write me"). The request silently carried
     * no required capabilities, so routing never looked for a
     * coding-capable model and fell back to whatever provider came first
     * by default instead - talking to the wrong model entirely, not the
     * dedicated code-writing one.
     */
    public function test_a_natural_request_to_write_code_is_recognized_as_coding(): void
    {
        $required = $this->resolver()->resolve('ممكن تكتبلى كود لصفحه منتجات', null);

        $this->assertContains('coding', $required);
    }

    public function test_a_discount_code_mention_with_no_creation_verb_does_not_falsely_require_coding(): void
    {
        $required = $this->resolver()->resolve('عايز كود الخصم بتاع الطلب', null);

        $this->assertNotContains('coding', $required);
    }

    public function test_asking_to_convert_data_to_json_in_arabic_requires_structured_output(): void
    {
        $required = $this->resolver()->resolve('حول البيانات دي إلى json', null);

        $this->assertContains('structured_output', $required);
    }

    public function test_asking_for_json_in_english_requires_structured_output(): void
    {
        $required = $this->resolver()->resolve('return this as json please', null);

        $this->assertContains('structured_output', $required);
    }

    public function test_asking_for_a_readable_table_does_not_falsely_require_structured_output(): void
    {
        // A markdown table is not JSON - this must not fire the
        // "respond with ONLY a JSON value" instruction for what is
        // really just a normal formatting preference.
        $required = $this->resolver()->resolve('رجعلي المقارنة في شكل جدول عادي', null);

        $this->assertNotContains('structured_output', $required);
    }

    /**
     * Root-cause fix - real, observed bug: "اعمل الصوره اللى طلبتها منك"
     * ("make the picture I asked you for") never matched the fixed phrase
     * "اعمل صورة"/"اعمل صوره" because of the inserted definite article
     * ("ال") - "اعمل" + "الصوره" is not the same substring as "اعمل" +
     * "صوره". required_capabilities silently stayed empty, so routing
     * picked an ordinary chat model instead of the real image-generation
     * one, and the user got an honest-but-wrong "I can't produce images"
     * reply even though a working image connector exists. See
     * mentionsGeneratingImage()'s docblock.
     */
    public function test_a_generation_request_with_a_definite_article_is_recognized_as_image_generation(): void
    {
        $required = $this->resolver()->resolve('اعمل الصوره اللى طلبتها منك', null);

        $this->assertContains('image_generation', $required);
    }

    public function test_make_me_a_logo_in_arabic_is_recognized_as_image_generation(): void
    {
        $required = $this->resolver()->resolve('اعملي لوجو لمطعمي', null);

        $this->assertContains('image_generation', $required);
    }

    public function test_make_an_image_in_english_is_recognized_as_image_generation(): void
    {
        $required = $this->resolver()->resolve('make an image of a sunset over the sea', null);

        $this->assertContains('image_generation', $required);
    }

    public function test_a_creation_verb_with_no_mention_of_a_picture_does_not_falsely_require_image_generation(): void
    {
        // "اعمل" ("make"/"do") alone is far too generic - e.g. "اعمل
        // حسابي يشتغل تاني" ("make my account work again") - and must
        // never fire image_generation without an actual mention of a
        // picture/photo/logo anywhere in the message.
        $required = $this->resolver()->resolve('اعمل حسابي يشتغل تاني من فضلك', null);

        $this->assertNotContains('image_generation', $required);
    }
}
