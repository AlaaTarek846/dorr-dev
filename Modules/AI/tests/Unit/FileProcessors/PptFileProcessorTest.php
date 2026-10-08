<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\PptFileProcessor;
use PhpOffice\PhpPresentation\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 (doc S4/S27): PptFileProcessor is classified
 * PARTIALLY_SUPPORTED in the Final Report - it uses phppresentation's
 * own `PowerPoint97` reader (a real, documented reader for legacy binary
 * .ppt, not a conversion shim), but that reader's fidelity could not be
 * exercised against a genuine legacy .ppt fixture in this environment
 * (phppresentation can only WRITE PowerPoint2007/ODP, not legacy PPT, so
 * there is no way to generate a real .ppt fixture here either - this is
 * a real, disclosed gap, not an oversight).
 */
class PptFileProcessorTest extends TestCase
{
    protected PptFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new PptFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-ppt-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_only_legacy_ppt(): void
    {
        $this->assertTrue($this->processor->supports('application/vnd.ms-powerpoint'));
        $this->assertFalse($this->processor->supports('application/vnd.openxmlformats-officedocument.presentationml.presentation'));
    }

    public function test_without_phppresentation_installed_it_fails_honestly_instead_of_faking_success(): void
    {
        if (class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is installed.');
        }

        $path = $this->tempDir.'/deck.ppt';
        file_put_contents($path, 'irrelevant - the dependency check happens before this file is even read');

        $result = $this->processor->process($path, 'application/vnd.ms-powerpoint');

        $this->assertFalse($result->success);
        $this->assertSame('PPT_PROCESSOR_UNAVAILABLE', $result->error);
    }

    public function test_a_file_that_is_not_a_real_legacy_ppt_binary_fails_cleanly(): void
    {
        if (! class_exists(IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phppresentation is not installed yet - run: composer require phpoffice/phppresentation');
        }

        $path = $this->tempDir.'/broken.ppt';
        file_put_contents($path, 'not a real legacy ppt binary at all');

        $result = $this->processor->process($path, 'application/vnd.ms-powerpoint');

        $this->assertFalse($result->success);
        $this->assertSame('PPT_PARSE_FAILED', $result->error);
    }
}
