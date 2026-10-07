<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\CsvFileProcessor;
use Modules\AI\Services\FileProcessors\ExcelFileProcessor;
use Modules\AI\Services\FileProcessors\HtmlFileProcessor;
use Modules\AI\Services\FileProcessors\JsonFileProcessor;
use Modules\AI\Services\FileProcessors\MarkdownFileProcessor;
use Modules\AI\Services\FileProcessors\PptFileProcessor;
use Modules\AI\Services\FileProcessors\PptxFileProcessor;
use Modules\AI\Services\FileProcessors\PdfFileProcessor;
use Modules\AI\Services\FileProcessors\AudioFileProcessor;
use Modules\AI\Services\FileProcessors\RasterImageFileProcessor;
use Modules\AI\Services\FileProcessors\SvgImageFileProcessor;
use Modules\AI\Services\FileProcessors\TextFileProcessor;
use Modules\AI\Services\FileProcessors\TsvFileProcessor;
use Modules\AI\Services\FileProcessors\VideoFileProcessor;
use Modules\AI\Services\FileProcessors\WordFileProcessor;
use Modules\AI\Services\FileProcessors\XmlFileProcessor;

/**
 * Doc S31: proves AiFileProcessorManager resolves every Phase 1 + Phase 2
 * + Phase 3 MIME type to its real, dedicated processor - and that an
 * unsupported type resolves to null rather than throwing (doc S18).
 * Phase 3 adds CSV/TSV, now their own dedicated processors rather than
 * ExcelFileProcessor's old generic-reader path.
 *
 * Phase 5 adds the raster image MIME types (-> RasterImageFileProcessor)
 * and SVG (-> SvgImageFileProcessor, resolved separately since it is
 * handled as XML, not a raster format).
 *
 * Phase 6 adds the audio MIME types (-> AudioFileProcessor).
 *
 * Phase 7 adds the video MIME types (-> VideoFileProcessor), INCLUDING
 * `video/webm` - which now resolves to VideoFileProcessor at the
 * manager level (registration order - see AiFileProcessorManager's own
 * docblock), not AudioFileProcessor, even for an audio-only WebM/Opus
 * upload: VideoFileProcessor inspects the real stream content and
 * delegates to AudioFileProcessor internally in that case (see
 * VideoFileProcessor's own docblock and VideoFileProcessorTest for the
 * content-level behavior) - this test only proves MIME->processor
 * resolution, not that deeper delegation.
 */
class AiFileProcessorManagerTest extends \Tests\TestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function mimeTypeProvider(): array
    {
        return [
            'pdf' => ['application/pdf', PdfFileProcessor::class],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', WordFileProcessor::class],
            'doc' => ['application/msword', WordFileProcessor::class],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ExcelFileProcessor::class],
            'xls' => ['application/vnd.ms-excel', ExcelFileProcessor::class],
            'csv' => ['text/csv', CsvFileProcessor::class],
            'tsv' => ['text/tab-separated-values', TsvFileProcessor::class],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', PptxFileProcessor::class],
            'ppt' => ['application/vnd.ms-powerpoint', PptFileProcessor::class],
            'txt' => ['text/plain', TextFileProcessor::class],
            'md' => ['text/markdown', MarkdownFileProcessor::class],
            'html' => ['text/html', HtmlFileProcessor::class],
            'json' => ['application/json', JsonFileProcessor::class],
            'xml' => ['application/xml', XmlFileProcessor::class],
            'jpeg' => ['image/jpeg', RasterImageFileProcessor::class],
            'png' => ['image/png', RasterImageFileProcessor::class],
            'webp' => ['image/webp', RasterImageFileProcessor::class],
            'gif' => ['image/gif', RasterImageFileProcessor::class],
            'bmp' => ['image/bmp', RasterImageFileProcessor::class],
            'tiff' => ['image/tiff', RasterImageFileProcessor::class],
            'svg' => ['image/svg+xml', SvgImageFileProcessor::class],
            'mp3' => ['audio/mpeg', AudioFileProcessor::class],
            'wav' => ['audio/wav', AudioFileProcessor::class],
            'm4a' => ['audio/mp4', AudioFileProcessor::class],
            'aac' => ['audio/aac', AudioFileProcessor::class],
            'ogg' => ['audio/ogg', AudioFileProcessor::class],
            'flac' => ['audio/flac', AudioFileProcessor::class],
            'webm_audio' => ['audio/webm', AudioFileProcessor::class],
            'webm_audio_as_video_mime' => ['video/webm', VideoFileProcessor::class],
            'mp4' => ['video/mp4', VideoFileProcessor::class],
            'mov' => ['video/quicktime', VideoFileProcessor::class],
            'avi' => ['video/x-msvideo', VideoFileProcessor::class],
        ];
    }

    /**
     * @dataProvider mimeTypeProvider
     */
    public function test_resolves_the_expected_processor_for_each_supported_mime_type(string $mimeType, string $expectedProcessor): void
    {
        $processor = app(\Modules\AI\Services\FileProcessors\AiFileProcessorManager::class)->for($mimeType);

        $this->assertNotNull($processor);
        $this->assertInstanceOf($expectedProcessor, $processor);
    }

    public function test_an_unsupported_mime_type_resolves_to_null_not_an_exception(): void
    {
        $manager = app(\Modules\AI\Services\FileProcessors\AiFileProcessorManager::class);

        $this->assertNull($manager->for('video/x-flv'));
        $this->assertFalse($manager->supports('video/x-flv'));
    }
}
