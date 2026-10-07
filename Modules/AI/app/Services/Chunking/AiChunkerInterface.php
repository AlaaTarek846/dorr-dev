<?php

namespace Modules\AI\Services\Chunking;

/**
 * Phase 8 (doc S5): strategy interface for the chunking engine, mirroring
 * AiFileProcessorInterface's own reasoning - one small class per real
 * content shape instead of one giant chunker with a type switch.
 *
 * A chunker NEVER re-parses the original file (doc S7) - it only reads
 * the NORMALIZED content already produced by a processor (text/blocks/
 * metadata, exactly what `AiFileEngine::getContext()` plus
 * `AiFile::metadata` already hand back), and it never touches AI
 * providers, embeddings, or retrieval (doc S3).
 */
interface AiChunkerInterface
{
    /**
     * Whether this chunker genuinely handles $documentType (e.g. 'pdf',
     * 'xlsx', 'pptx') - checked by AiChunkerManager::for(), never
     * guessed from the file's MIME type directly (the processor already
     * normalized that into `document_type`).
     *
     * @param  array{document_type: ?string, text: ?string, blocks: list<array<string, mixed>>, metadata: array<string, mixed>}  $normalizedContent
     */
    public function supports(array $normalizedContent): bool;

    /**
     * @param  array{document_type: ?string, text: ?string, blocks: list<array<string, mixed>>, metadata: array<string, mixed>}  $normalizedContent
     * @return list<AiChunkDraft>
     */
    public function chunk(array $normalizedContent): array;
}
