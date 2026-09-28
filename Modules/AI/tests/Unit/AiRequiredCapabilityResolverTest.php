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
        $required = $this->resolver()->resolve('اصنع صوره فيها طفل صغير', null);

        $this->assertNotContains('image_generation', $required);
    }

    public function test_an_attached_image_with_no_edit_wording_still_only_requires_vision(): void
    {
        $required = $this->resolver()->resolve('اشرحلي اللي في الصورة دي', 'image/png');

        $this->assertContains('vision', $required);
        $this->assertNotContains('image_generation', $required);
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
}
