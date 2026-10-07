<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\ImageReferenceChunker;
use PHPUnit\Framework\TestCase;

class ImageReferenceChunkerTest extends TestCase
{
    public function test_whole_image_file_produces_one_record_with_metadata_not_free_text(): void
    {
        $chunker = new ImageReferenceChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'image',
            'blocks' => [],
            'metadata' => ['width' => 800, 'height' => 600, 'format' => 'jpeg', 'ocr_text' => 'Invoice #123'],
        ]);

        $this->assertCount(1, $drafts);
        $this->assertSame('image_reference', $drafts[0]->contentType);
        $this->assertSame(800, $drafts[0]->metadata['width']);
        $this->assertTrue($drafts[0]->metadata['has_ocr_text']);
        $this->assertStringContainsString('Invoice #123', $drafts[0]->content);
    }

    public function test_image_with_no_caption_or_ocr_still_produces_a_record(): void
    {
        $chunker = new ImageReferenceChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'image',
            'blocks' => [],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);

        $this->assertCount(1, $drafts);
        $this->assertSame('', $drafts[0]->content);
        $this->assertFalse($drafts[0]->metadata['has_ocr_text'] ?? false);
    }

    public function test_never_triggers_ocr_itself_just_packages_existing_metadata(): void
    {
        $chunker = new ImageReferenceChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'word',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'ignored by this strategy'],
                ['type' => 'image_reference', 'index' => 2],
            ],
            'metadata' => [],
        ]);

        $this->assertCount(1, $drafts);
        $this->assertSame(2, $drafts[0]->metadata['index']);
    }
}
