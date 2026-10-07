<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\AiChunkerManager;
use Modules\AI\Services\Chunking\Strategies\DocumentChunker;
use Modules\AI\Services\Chunking\Strategies\ImageReferenceChunker;
use Modules\AI\Services\Chunking\Strategies\SpreadsheetChunker;
use Tests\TestCase;

class AiChunkerManagerTest extends TestCase
{
    public function test_resolves_spreadsheet_strategy_for_sheet_metadata(): void
    {
        $manager = app(AiChunkerManager::class);

        $strategy = $manager->for(['metadata' => ['sheets' => [['name' => 'Sheet1']]]]);

        $this->assertInstanceOf(SpreadsheetChunker::class, $strategy);
    }

    public function test_resolves_document_strategy_for_pdf(): void
    {
        $manager = app(AiChunkerManager::class);

        $strategy = $manager->for(['document_type' => 'pdf', 'blocks' => [['type' => 'paragraph', 'text' => 'x']]]);

        $this->assertInstanceOf(DocumentChunker::class, $strategy);
    }

    public function test_resolves_image_strategy_before_generic_document(): void
    {
        $manager = app(AiChunkerManager::class);

        $strategy = $manager->for(['document_type' => 'image', 'blocks' => [], 'metadata' => ['width' => 10, 'height' => 10]]);

        $this->assertInstanceOf(ImageReferenceChunker::class, $strategy);
    }

    public function test_returns_null_for_a_genuinely_unsupported_shape_never_pretends_to_support_it(): void
    {
        $manager = app(AiChunkerManager::class);

        $strategy = $manager->for(['document_type' => null, 'blocks' => [], 'metadata' => [], 'text' => null]);

        $this->assertNull($strategy);
    }
}
