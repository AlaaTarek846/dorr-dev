<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\PptxFileProcessor;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Style\Bullet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4: proves the honest degrade path (doc S4: "do not fake native
 * support") for this project's actual current state - `phpoffice/
 * phppresentation` is not yet in composer.json (see the Phase 4 report
 * for the exact command to run). Once it is installed, the real
 * structural-extraction tests below run instead of skipping themselves -
 * consistent with how PdfFileProcessorTest already handles the same
 * situation for smalot/pdfparser.
 */
class PptxFileProcessorTest extends TestCase
{
    protected PptxFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new PptxFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-pptx-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_only_pptx(): void
    {
        $this->assertTrue($this->processor->supports('application/vnd.openxmlformats-officedocument.presentationml.presentation'));
        $this->assertFalse($this->processor->supports('application/vnd.ms-powerpoint'));
        $this->assertFalse($this->processor->supports('application/pdf'));
    }

    public function test_without_phppresentation_installed_it_fails_honestly_instead_of_faking_success(): void
    {
        if (class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is installed - see test_extracts_slides_from_a_real_pptx_file for the real path instead.');
        }

        $path = $this->tempDir.'/deck.pptx';
        file_put_contents($path, 'irrelevant - the dependency check happens before this file is even read');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertFalse($result->success);
        $this->assertSame('PPTX_PROCESSOR_UNAVAILABLE', $result->error);
    }

    public function test_extracts_slides_titles_bullets_and_tables_from_a_real_pptx_file(): void
    {
        if (! class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is not installed yet - run: composer require phpoffice/phppresentation');
        }

        $presentation = new PhpPresentation;
        $presentation->removeSlideByIndex(0);

        $slide = $presentation->createSlide();
        $title = $slide->createRichTextShape();
        $title->createTextRun('Executive Summary');

        $body = $slide->createRichTextShape();
        $p1 = $body->createParagraph();
        $p1->createTextRun('Revenue increased this quarter.');
        $p2 = $body->createParagraph();
        $p2->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET);
        $p2->createTextRun('Costs decreased');

        $path = $this->tempDir.'/deck.pptx';
        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->metadata['slide_count']);
        $this->assertSame('pptx', $result->documentType);

        $slideMeta = $result->metadata['slides'][0];
        $this->assertSame(1, $slideMeta['number']);
        $this->assertFalse($slideMeta['hidden']);

        $blockTypes = array_column($slideMeta['blocks'], 'type');
        $this->assertContains('paragraph', $blockTypes);
        $this->assertContains('list', $blockTypes);
    }

    public function test_arabic_slide_text_is_preserved(): void
    {
        if (! class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is not installed yet - run: composer require phpoffice/phppresentation');
        }

        $presentation = new PhpPresentation;
        $presentation->removeSlideByIndex(0);
        $slide = $presentation->createSlide();
        $body = $slide->createRichTextShape();
        $body->createParagraph()->createTextRun('الإيرادات زادت في القاهرة');

        $path = $this->tempDir.'/arabic.pptx';
        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('القاهرة', (string) $result->text);
    }

    public function test_hidden_slide_is_preserved_and_flagged(): void
    {
        if (! class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is not installed yet - run: composer require phpoffice/phppresentation');
        }

        $presentation = new PhpPresentation;
        $presentation->removeSlideByIndex(0);
        $visible = $presentation->createSlide();
        $visible->createRichTextShape()->createParagraph()->createTextRun('Visible');

        $hidden = $presentation->createSlide();
        $hidden->setIsVisible(false);
        $hidden->createRichTextShape()->createParagraph()->createTextRun('Internal only');

        $path = $this->tempDir.'/hidden.pptx';
        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->metadata['slides']);
        $this->assertFalse($result->metadata['slides'][0]['hidden']);
        $this->assertTrue($result->metadata['slides'][1]['hidden']);
        // The hidden slide's content is still preserved (doc S19: "do NOT
        // delete them"), just excluded from the flat text preview.
        $this->assertStringNotContainsString('Internal only', (string) $result->text);
        $blockTexts = array_column($result->metadata['slides'][1]['blocks'], 'text');
        $this->assertContains('Internal only', $blockTexts);
    }

    public function test_corrupted_pptx_fails_cleanly(): void
    {
        if (! class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is not installed yet - run: composer require phpoffice/phppresentation');
        }

        $path = $this->tempDir.'/broken.pptx';
        file_put_contents($path, 'not a real pptx file at all');

        $result = $this->processor->process($path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertFalse($result->success);
        $this->assertSame('PPTX_PARSE_FAILED', $result->error);
    }
}
