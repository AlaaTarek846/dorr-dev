<?php

namespace Modules\AI\Services\Chunking;

/**
 * Carries one of the doc's normalized error codes (CHUNKING_UNSUPPORTED_TYPE/
 * CHUNKING_EMPTY_CONTENT/CHUNKING_TOO_LARGE/CHUNKING_FAILED) so callers
 * (the queue job, tests) can branch on a stable code rather than parsing
 * a free-text message.
 */
class AiChunkingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
