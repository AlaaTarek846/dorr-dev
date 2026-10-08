<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Support\AiChatLexicon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiChatLexiconVideoTest extends TestCase
{
    public static function wanted(): array
    {
        return [
            ['اعمللي فيديو لقطة بتجري'], ['عايز فيديو لبحر وموج'], ['ولد فيديو لمدينة'], ['سويلي فيديو لسيارة'],
            ['make a video of waves on a beach'], ['generate a video of a cat'], ['صمم فيديو إعلاني لمطعم'],
        ];
    }

    public static function notWanted(): array
    {
        return [
            ['اعمل ملخص للفيديو'], ['لخصلي الفيديو ده'], ['اشرح اللي في الفيديو'], ['summarize this video'],
            ['اعمل صورة قطة'], ['مرحبا'], ['transcribe the video'],
        ];
    }

    #[DataProvider('wanted')]
    public function test_recognises_a_video_request(string $text): void
    {
        $this->assertTrue(AiChatLexicon::wantsVideoGeneration($text), $text);
    }

    #[DataProvider('notWanted')]
    public function test_ignores_analysis_images_and_chatter(string $text): void
    {
        $this->assertFalse(AiChatLexicon::wantsVideoGeneration($text), $text);
    }
}
