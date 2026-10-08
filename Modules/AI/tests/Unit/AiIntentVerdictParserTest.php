<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\AiIntentDecision;
use Modules\AI\Support\AiIntentVerdictParser;
use Modules\AI\Support\ArabicTextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The intent-router model's answer is untrusted input. These tests pin
 * that it is parsed defensively and that only safe, specific phrases can
 * ever become dictionary entries.
 */
class AiIntentVerdictParserTest extends TestCase
{
    public function test_a_clean_answer_is_parsed(): void
    {
        $v = AiIntentVerdictParser::parse('{"intents":["image_generation"],"confidence":0.93,"file_format":null,"trigger_phrase":"تخيل لي"}', false);

        $this->assertSame(['image_generation'], $v['intents']);
        $this->assertSame(0.93, $v['confidence']);
        $this->assertSame('تخيل لي', $v['trigger_phrase']);
    }

    public function test_markdown_fences_and_chatter_around_the_json_are_tolerated(): void
    {
        $v = AiIntentVerdictParser::parse("Sure!\n```json\n{\"intents\":[\"web_search\"],\"confidence\":0.8}\n```", false);

        $this->assertSame(['web_search'], $v['intents']);
    }

    public function test_garbage_is_rejected(): void
    {
        $this->assertNull(AiIntentVerdictParser::parse('I think they want an image.', false));
        $this->assertNull(AiIntentVerdictParser::parse('{not json}', false));
        $this->assertNull(AiIntentVerdictParser::parse('[]', false));
    }

    public function test_intents_outside_the_closed_vocabulary_are_dropped(): void
    {
        $v = AiIntentVerdictParser::parse('{"intents":["delete_account","chat","web_search","  CODING "],"confidence":0.9}', false);

        $this->assertSame(['web_search', 'coding'], $v['intents']);
    }

    public function test_confidence_is_clamped_and_non_numbers_become_zero(): void
    {
        $this->assertSame(1.0, AiIntentVerdictParser::parse('{"intents":["coding"],"confidence":7}', false)['confidence']);
        $this->assertSame(0.0, AiIntentVerdictParser::parse('{"intents":["coding"],"confidence":"high"}', false)['confidence']);
    }

    public function test_image_edit_needs_an_image_otherwise_it_is_a_new_image(): void
    {
        $raw = '{"intents":["image_edit"],"confidence":0.9}';

        $this->assertSame(['image_generation'], AiIntentVerdictParser::parse($raw, false)['intents']);
        $this->assertSame(['image_edit'], AiIntentVerdictParser::parse($raw, true)['intents']);
        $this->assertSame(
            ['image_edit'],
            AiIntentVerdictParser::parse('{"intents":["image_generation","image_edit"],"confidence":0.9}', true)['intents'],
        );
    }

    public function test_file_format_is_only_kept_for_file_output_and_is_whitelisted(): void
    {
        $this->assertSame('docx', AiIntentVerdictParser::parse('{"intents":["file_output"],"confidence":0.9,"file_format":"Word"}', false)['file_format']);
        $this->assertSame('xlsx', AiIntentVerdictParser::parse('{"intents":["file_output"],"confidence":0.9,"file_format":".xls"}', false)['file_format']);
        $this->assertNull(AiIntentVerdictParser::parse('{"intents":["file_output"],"confidence":0.9,"file_format":"exe"}', false)['file_format']);
        $this->assertNull(AiIntentVerdictParser::parse('{"intents":["coding"],"confidence":0.9,"file_format":"pdf"}', false)['file_format']);
    }

    public function test_a_phrase_must_literally_appear_in_the_message(): void
    {
        $message = ArabicTextNormalizer::normalize('تخيل لي بيت على البحر وقت الغروب');

        $this->assertSame('تخيل لي', AiIntentVerdictParser::learnablePhrase('تخيل لي', $message));
        $this->assertSame('تخيل لي', AiIntentVerdictParser::learnablePhrase('تَخيّل لي', $message));
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('ارسم لي بيت', $message), 'invented words');
    }

    public function test_generic_or_unsafe_phrases_are_never_learned(): void
    {
        $message = ArabicTextNormalizer::normalize('ممكن تعمللي صورة لو سمحت 12345 test@example.com');

        $this->assertNull(AiIntentVerdictParser::learnablePhrase('ممكن', $message), 'single word');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('لو سمحت', $message), 'only stopwords');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('give me', ArabicTextNormalizer::normalize('give me a poster')), 'generic verb + stopword');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('سمحت 12345', $message), 'digits');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('12345 test@example.com', $message), 'e-mail');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase(null, $message));
    }

    public function test_a_phrase_made_only_of_generic_words_is_not_learned_but_a_specific_one_is(): void
    {
        $message = ArabicTextNormalizer::normalize('Show me what a dragon looks like');

        $this->assertNull(AiIntentVerdictParser::learnablePhrase('show me what', $message), 'question word is not a content word');
        $this->assertNull(AiIntentVerdictParser::learnablePhrase('show me', $message));
        $this->assertSame('imagine a dragon', AiIntentVerdictParser::learnablePhrase('imagine a dragon', ArabicTextNormalizer::normalize('Please imagine a dragon flying')));
    }

    public function test_language_detection(): void
    {
        $this->assertSame('ar', AiIntentVerdictParser::languageOf('تخيل لي بيت'));
        $this->assertSame('en', AiIntentVerdictParser::languageOf('imagine a house'));
        $this->assertSame('mixed', AiIntentVerdictParser::languageOf('اعمل pdf'));
    }

    public function test_decision_maps_intents_to_capabilities_and_flags(): void
    {
        $d = new AiIntentDecision([AiChatIntent::IMAGE_EDIT, AiChatIntent::WEB_SEARCH, AiChatIntent::VOICE_REPLY, AiChatIntent::FILE_OUTPUT], 'xlsx', 'model', 0.9);

        $this->assertSame(['image_generation', 'web_search'], $d->capabilities());
        $this->assertTrue($d->wantsVoiceReply());
        $this->assertTrue($d->wantsFileOutput());
        $this->assertTrue($d->wantsImageEdit());
        $this->assertSame('xlsx', $d->fileFormat);
        $this->assertTrue(AiIntentDecision::none()->isEmpty());
        $this->assertSame([], AiIntentDecision::none()->capabilities());
    }
}
