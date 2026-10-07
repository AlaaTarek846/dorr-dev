<?php

namespace Modules\AI\Services\Chunking;

/**
 * Phase 8: the normalized output every AiChunkerInterface implementation
 * returns - mirrors why AiFileProcessingResult exists (one shared shape
 * instead of each chunker inventing its own), per this phase's own rule
 * 3 ("do not put retrieval logic inside the chunker... do not make
 * processors responsible for embeddings") - a chunker's only job is to
 * produce these, nothing else.
 *
 * `chunkKey` is NOT set here - AiChunkingEngine computes it once it
 * knows the file id and content version (doc S9: "file_id + content
 * version + chunk index + chunk checksum"), so a chunker never has to
 * know about the owning AiFile at all (same separation-of-concerns
 * reasoning AiFileProcessorInterface already uses: a processor never
 * knows its own AiFile id either).
 */
class AiChunkDraft
{
    /**
     * @param  array<string, mixed>  $metadata  Doc S12/S46: structured
     *                                           source references
     *                                           (page/section/sheet_name/
     *                                           row_start/row_end/
     *                                           slide_number/
     *                                           timestamp_start/
     *                                           timestamp_end/path/
     *                                           language/...) - format-
     *                                           specific, so kept as one
     *                                           flexible bag rather than
     *                                           a dozen mostly-null
     *                                           typed columns (same
     *                                           reasoning `ai_files.metadata`
     *                                           already uses - see this
     *                                           phase's report).
     */
    public function __construct(
        public readonly int $index,
        public readonly string $content,
        public readonly string $contentType,
        public readonly array $metadata = [],
    ) {}

    public function characterCount(): int
    {
        return mb_strlen($this->content);
    }

    /**
     * Doc S31: deterministic per-chunk checksum of normalized content +
     * the metadata that actually identifies its source position (not
     * every metadata key - e.g. a language tag changing would not make
     * this a "different" chunk) - used for dedup/change-detection, never
     * for anything security-sensitive.
     */
    public function contentChecksum(): string
    {
        return md5($this->content.'|'.json_encode($this->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
