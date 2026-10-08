<?php

namespace Modules\AI\Services\Chunking;

use Modules\AI\Services\Chunking\Strategies\AudioTranscriptChunker;
use Modules\AI\Services\Chunking\Strategies\DocumentChunker;
use Modules\AI\Services\Chunking\Strategies\HtmlChunker;
use Modules\AI\Services\Chunking\Strategies\ImageReferenceChunker;
use Modules\AI\Services\Chunking\Strategies\PresentationChunker;
use Modules\AI\Services\Chunking\Strategies\SpreadsheetChunker;
use Modules\AI\Services\Chunking\Strategies\StructuredDataChunker;
use Modules\AI\Services\Chunking\Strategies\VideoTranscriptChunker;

/**
 * Phase 8 (doc S10): resolves a chunker strategy for a given normalized
 * content array, mirroring AiFileProcessorManager's own `for()` pattern
 * exactly. Adding a new strategy later means appending one line to
 * `$strategies` - never modifying any existing strategy class.
 */
class AiChunkerManager
{
    /** @var list<AiChunkerInterface> */
    private array $strategies;

    public function __construct()
    {
        // Order matters: more specific strategies (spreadsheet/
        // presentation/transcript/image) are checked before the
        // generic DocumentChunker, since a processor could in theory
        // emit both a document_type and structured metadata.
        $this->strategies = [
            app(VideoTranscriptChunker::class),
            app(AudioTranscriptChunker::class),
            app(ImageReferenceChunker::class),
            app(SpreadsheetChunker::class),
            app(PresentationChunker::class),
            app(StructuredDataChunker::class),
            app(HtmlChunker::class),
            app(DocumentChunker::class),
        ];
    }

    /**
     * @param  array{document_type: ?string, text: ?string, blocks: list<array<string,mixed>>, metadata: array<string,mixed>}  $normalizedContent
     */
    public function for(array $normalizedContent): ?AiChunkerInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($normalizedContent)) {
                return $strategy;
            }
        }

        // Doc S46: never pretend to support an unsupported type - return
        // null, the caller (AiChunkingEngine) turns this into the
        // explicit CHUNKING_UNSUPPORTED_TYPE error code rather than
        // throwing or silently producing zero chunks.
        return null;
    }
}
