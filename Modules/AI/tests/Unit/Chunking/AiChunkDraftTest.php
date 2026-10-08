<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\AiChunkDraft;
use PHPUnit\Framework\TestCase;

class AiChunkDraftTest extends TestCase
{
    public function test_character_count_uses_multibyte_length(): void
    {
        $draft = new AiChunkDraft(0, 'مرحبا', 'document');

        $this->assertSame(5, $draft->characterCount());
    }

    public function test_checksum_is_deterministic_for_same_content_and_metadata(): void
    {
        $a = new AiChunkDraft(0, 'same content', 'document', ['page' => 1]);
        $b = new AiChunkDraft(0, 'same content', 'document', ['page' => 1]);

        $this->assertSame($a->contentChecksum(), $b->contentChecksum());
    }

    public function test_checksum_changes_when_content_changes(): void
    {
        $a = new AiChunkDraft(0, 'content A', 'document');
        $b = new AiChunkDraft(0, 'content B', 'document');

        $this->assertNotSame($a->contentChecksum(), $b->contentChecksum());
    }

    public function test_checksum_changes_when_metadata_changes(): void
    {
        $a = new AiChunkDraft(0, 'same content', 'document', ['page' => 1]);
        $b = new AiChunkDraft(0, 'same content', 'document', ['page' => 2]);

        $this->assertNotSame($a->contentChecksum(), $b->contentChecksum());
    }
}
