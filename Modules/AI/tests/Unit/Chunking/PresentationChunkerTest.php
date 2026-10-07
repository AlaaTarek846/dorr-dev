<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\Strategies\PresentationChunker;
use Tests\TestCase;

class PresentationChunkerTest extends TestCase
{
    public function test_chunks_by_slide_preserving_slide_number(): void
    {
        $chunker = new PresentationChunker;

        $drafts = $chunker->chunk([
            'metadata' => [
                'slides' => [
                    ['title' => 'Intro', 'blocks' => [['type' => 'paragraph', 'text' => 'Welcome.']], 'notes' => null, 'source' => ['slide' => 1]],
                    ['title' => 'Agenda', 'blocks' => [['type' => 'list', 'items' => ['Item A', 'Item B']]], 'notes' => 'Speaker note', 'source' => ['slide' => 2]],
                ],
            ],
        ]);

        $this->assertCount(2, $drafts);
        $this->assertSame(1, $drafts[0]->metadata['slide_number']);
        $this->assertSame(2, $drafts[1]->metadata['slide_number']);
        $this->assertStringContainsString('Welcome', $drafts[0]->content);
        $this->assertStringContainsString('Speaker note', $drafts[1]->content);
    }

    public function test_oversized_slide_is_split_but_keeps_slide_number(): void
    {
        config(['ai.chunking.presentation.max_characters' => 100]);

        $chunker = new PresentationChunker;

        $longText = trim(str_repeat('A long bullet point about the roadmap. ', 40));

        $drafts = $chunker->chunk([
            'metadata' => [
                'slides' => [
                    ['title' => 'Roadmap', 'blocks' => [['type' => 'paragraph', 'text' => $longText]], 'notes' => null, 'source' => ['slide' => 5]],
                ],
            ],
        ]);

        $this->assertGreaterThan(1, count($drafts));

        foreach ($drafts as $draft) {
            $this->assertSame(5, $draft->metadata['slide_number']);
        }
    }
}
