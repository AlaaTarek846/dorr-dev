<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Support\AiChatLexicon;
use Modules\AI\Support\ArabicTextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The chat layer switches capabilities (voice reply, file output, image
 * generation/edit, web search, code, ...) from free-typed Egyptian, Saudi
 * and English text. These tests pin the behaviour users actually hit:
 * spelling variants (ى/ي, ة/ه), dialect phrasing, AND the false positives
 * that would make the chat feel "wrong" (a normal question wrongly turned
 * into a voice note, a file, or an image).
 */
class AiChatLexiconTest extends TestCase
{
    public function test_normalizer_unifies_spelling_variants(): void
    {
        $this->assertSame('قولي برد صوتي', ArabicTextNormalizer::normalize('قولى برد صوتى'));
        $this->assertSame('الصوره', ArabicTextNormalizer::normalize('الصورَة'));
        $this->assertSame('اعمل الي صوره', ArabicTextNormalizer::normalize('أعمل إلي صورة؟'));
        $this->assertSame('اعملي لوجو', ArabicTextNormalizer::normalize('اعملللللي لوجو'));
        $this->assertSame('اللي', ArabicTextNormalizer::normalize('اللي'));
        $this->assertSame('123', ArabicTextNormalizer::normalize('١٢٣'));
    }

    #[DataProvider('voiceYes')]
    public function test_voice_reply_is_detected(string $text): void
    {
        $this->assertTrue(AiChatLexicon::wantsVoiceReply($text), $text);
    }

    public static function voiceYes(): array
    {
        return array_map(fn ($t) => [$t], [
            'قولى برد صوتى', 'رد صوتي لو سمحت', 'ابغى رد صوتي', 'ابعتلي فويس', 'reply with voice please',
            'read it out loud', 'اعملها رد صوتى', 'سمعني الرد', 'Can you answer by voice?',
            'ارسل لي رسالة صوتية', 'اقراها بصوت عالي', 'رد بالصوت',
        ]);
    }

    #[DataProvider('voiceNo')]
    public function test_voice_reply_is_not_a_false_positive(string $text): void
    {
        $this->assertFalse(AiChatLexicon::wantsVoiceReply($text), $text);
    }

    public static function voiceNo(): array
    {
        return array_map(fn ($t) => [$t], [
            'عايز اسمع رأيك', 'talk to me about cars', 'بدون صوت اكتب بس', 'no voice please',
            'اكتبلي الرد', 'سمعني رأيك في الموضوع',
        ]);
    }

    #[DataProvider('imageGenerationYes')]
    public function test_image_generation_is_detected(string $text): void
    {
        $this->assertTrue(AiChatLexicon::wantsImageGeneration($text), $text);
    }

    public static function imageGenerationYes(): array
    {
        return array_map(fn ($t) => [$t], [
            'اعمل صوره فيها كلب بيضحك مع طفل', 'اصنع صوره فيها طفل صغير', 'اعمل الصوره اللى طلبتها منك',
            'ارسملي قطه', 'سويلي لوجو لشركتي', 'ابغى لوجو', 'generate an image of a cat',
            'can you make me a logo', 'صمم بوستر لمعرض', 'Draw a dragon', 'اعملهالي صورة',
        ]);
    }

    #[DataProvider('imageGenerationNo')]
    public function test_image_generation_is_not_a_false_positive(string $text): void
    {
        $this->assertFalse(AiChatLexicon::wantsImageGeneration($text), $text);
    }

    public static function imageGenerationNo(): array
    {
        return array_map(fn ($t) => [$t], [
            'اشرحلي اللي في الصوره', 'give me a summary of this photo', 'what is in this image',
            'اعمل رسالة رسمية للمدير', 'اكتب كود لتصوير الشاشة', 'describe this picture', 'ايه ده',
        ]);
    }

    public function test_image_edit_detection(): void
    {
        $this->assertTrue(AiChatLexicon::wantsImageEdit('غيرلي لون الصورة وخليه أحمر'));
        $this->assertTrue(AiChatLexicon::wantsImageEdit('زود كمان على الصوره قطه بتضحك'));
        $this->assertTrue(AiChatLexicon::wantsImageEdit('خلى الطفل ده لابس بدلة', true));
        $this->assertTrue(AiChatLexicon::wantsImageEdit('remove the background from this photo'));
        $this->assertFalse(AiChatLexicon::wantsImageEdit('خليك هادي'));
        $this->assertFalse(AiChatLexicon::wantsImageEdit('describe this image', true));
        // "صغير" ends with the letters of the verb "غير" - must not count.
        $this->assertFalse(AiChatLexicon::wantsImageEdit('ايه رايك فى صوره طفل صغير'));
    }

    #[DataProvider('fileYes')]
    public function test_file_output_is_detected(string $text): void
    {
        $this->assertTrue(AiChatLexicon::wantsFileOutput($text), $text);
    }

    public static function fileYes(): array
    {
        return array_map(fn ($t) => [$t], [
            'اعملي ملف pdf عن اهم المعالم', 'حولها لوورد', 'ابعتلي النتيجه في ملف اكسيل', 'export this to excel',
            'make a pdf about cats', 'اعمل تقرير pdf', 'سويلي ملف وورد', 'download it', 'create a word document',
            'حطها فى ملف',
        ]);
    }

    #[DataProvider('fileNo')]
    public function test_reading_an_input_file_is_not_a_file_request(string $text): void
    {
        $this->assertFalse(AiChatLexicon::wantsFileOutput($text), $text);
    }

    public static function fileNo(): array
    {
        return array_map(fn ($t) => [$t], [
            'لخص الملف ده', 'summarize this pdf', 'what is a pdf', 'اشرحلي الاكسيل ده',
            'make it a one word answer', 'حلل الاكسيل',
        ]);
    }

    public function test_output_format_wins_over_input_format(): void
    {
        $this->assertSame('xlsx', AiChatLexicon::detectRequestedFileFormat('ابعتلي النتيجه في ملف اكسيل'));
        $this->assertSame('docx', AiChatLexicon::detectRequestedFileFormat('حولها لوورد'));
        $this->assertSame('pdf', AiChatLexicon::detectRequestedFileFormat('حلل الاكسيل واعملي تقرير pdf'));
        $this->assertSame('xlsx', AiChatLexicon::detectRequestedFileFormat('خد الـ pdf ده وحوله الى excel'));
        $this->assertSame('pdf', AiChatLexicon::detectRequestedFileFormat('اعمل ملف عن مصر'));
    }

    public function test_web_search_research_study_json_and_code(): void
    {
        foreach (['ابحث على النت عن اسعار الشقق', 'سعر الذهب النهارده', 'latest news about AI', 'وش اخر اخبار الهلال', 'ايه الجديد في laravel', 'weather in Riyadh'] as $t) {
            $this->assertTrue(AiChatLexicon::wantsWebSearch($t), $t);
        }
        foreach (['ازاي اطبخ رز', 'how does a for loop work', 'what time is it'] as $t) {
            $this->assertFalse(AiChatLexicon::wantsWebSearch($t), $t);
        }
        foreach (['ممكن تكتبلى كود لصفحه منتجات', 'اكتب كود بايثون', 'write a function to sort', 'برمجلي سكريبت', 'سوي لي كود sql'] as $t) {
            $this->assertTrue(AiChatLexicon::wantsCode($t), $t);
        }
        foreach (['عايز كود خصم', 'what is the country code', 'اعمل صوره'] as $t) {
            $this->assertFalse(AiChatLexicon::wantsCode($t), $t);
        }
        foreach (['ذاكرلي الفيزياء', 'explain this to me', 'help me study', 'ساعدني افهم الدرس'] as $t) {
            $this->assertTrue(AiChatLexicon::wantsStudyHelp($t), $t);
        }
        foreach (['ورقه بحثيه عن المناخ', 'write a research paper', 'مع المصادر'] as $t) {
            $this->assertTrue(AiChatLexicon::wantsResearch($t), $t);
        }
        foreach (['رجعهالي json', 'return it as json', 'بصيغه json'] as $t) {
            $this->assertTrue(AiChatLexicon::wantsStructuredOutput($t), $t);
        }
    }
}
