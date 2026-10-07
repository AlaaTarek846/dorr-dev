<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Strategy interface for the Universal AI File Engine (master plan #4):
 * one small class per real file family instead of one giant service that
 * grows an if/else per MIME type. Each processor owns exactly one job -
 * turn a file on disk into plain text (for the model to read) plus
 * structured metadata (for previews/limits/page-aware answers) - and is
 * honest when it cannot: a processor that does not genuinely support a
 * type is simply never selected by AiFileProcessorManager (master plan
 * #46 - "do not fake file support").
 */
interface AiFileProcessorInterface
{
    /**
     * Whether this processor can genuinely handle $mimeType - checked by
     * AiFileProcessorManager::for(), never guessed from the file
     * extension alone (master plan #5).
     */
    public function supports(string $mimeType): bool;

    /**
     * Never throws for a malformed/unusual file of a supported type -
     * degrades to AiFileProcessingResult::failed() instead (mirrors
     * AiDocumentTextExtractor's existing "a bad PDF should degrade, not
     * break chat" contract). A genuinely unexpected error (disk I/O,
     * out-of-memory guard, ...) is still allowed to throw - the caller
     * (ProcessAiFileJob) is responsible for catching and recording that.
     */
    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult;
}
