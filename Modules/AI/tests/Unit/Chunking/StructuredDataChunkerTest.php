<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\StructuredDataChunker;
use Tests\TestCase;

class StructuredDataChunkerTest extends TestCase
{
    public function test_groups_by_top_level_path_rather_than_one_giant_string(): void
    {
        $chunker = new StructuredDataChunker;

        $drafts = $chunker->chunk([
            'document_type' => 'json',
            'blocks' => [
                ['type' => 'data', 'path' => 'customers[0].name', 'value' => 'Ali'],
                ['type' => 'data', 'path' => 'customers[0].orders[0].total', 'value' => 150],
                ['type' => 'data', 'path' => 'customers[1].name', 'value' => 'Sara'],
            ],
        ]);

        $paths = array_map(fn ($d) => $d->metadata['path'], $drafts);

        $this->assertContains('customers[0]', $paths);
        $this->assertContains('customers[1]', $paths);
        $this->assertNotContains('root', $paths);
    }

    public function test_supports_data_blocks_regardless_of_document_type_label(): void
    {
        $chunker = new StructuredDataChunker;

        $this->assertTrue($chunker->supports(['blocks' => [['type' => 'data', 'path' => 'a', 'value' => 1]]]));
        $this->assertFalse($chunker->supports(['blocks' => [['type' => 'paragraph', 'text' => 'x']]]));
    }
}
