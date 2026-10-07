<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 2: JSON is parsed with PHP's built-in json_decode() (no new
 * dependency) rather than flattened into one giant string (doc S20).
 * PHP has no built-in streaming JSON decoder, so for a very large file
 * this still loads the whole document into memory once - the existing
 * upload-time `ai.files.max_size_bytes` limit (enforced by AiFileEngine
 * before this processor ever runs) is this phase's real bound; this
 * processor additionally caps decode depth and the number of leaf
 * "data" blocks it will produce, and fails cleanly (doc S21: "never
 * allow a large malformed JSON file to crash the worker") rather than
 * trying to flatten an unbounded structure.
 */
class JsonFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['application/json', 'text/json'];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $raw = @file_get_contents($absolutePath);

        if ($raw === false) {
            return AiFileProcessingResult::failed('could_not_read_file');
        }

        $maxDepth = (int) config('ai.files.json_max_depth', 64);
        $decoded = json_decode($raw, true, $maxDepth);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Doc S21/S30: a malformed/too-deep file is a clean,
            // non-retryable error - never a 500, never a crash.
            return AiFileProcessingResult::failed('JSON_INVALID');
        }

        $maxLeafBlocks = (int) config('ai.files.json_max_leaf_blocks', 500);
        $blocks = [];
        $warnings = [];
        $truncated = false;

        $this->flatten($decoded, '$', $blocks, $maxLeafBlocks, $truncated);

        if ($truncated) {
            $warnings[] = 'json_truncated_leaf_blocks';
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $raw;
        $textTruncated = mb_strlen($pretty) > 20000;
        $text = $textTruncated
            ? mb_substr($pretty, 0, 20000)."\n".__('ai.document_truncated_note')
            : $pretty;

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: [
                'root_type' => $this->typeOf($decoded),
                'leaf_block_count' => count($blocks),
            ],
            blocks: $blocks,
            warnings: $warnings,
            documentType: 'json',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function flatten(mixed $value, string $path, array &$blocks, int $maxLeafBlocks, bool &$truncated): void
    {
        if (count($blocks) >= $maxLeafBlocks) {
            $truncated = true;

            return;
        }

        if (is_array($value)) {
            $isList = array_is_list($value);

            foreach ($value as $key => $child) {
                if (count($blocks) >= $maxLeafBlocks) {
                    $truncated = true;

                    return;
                }

                $childPath = $isList ? "{$path}[{$key}]" : "{$path}.{$key}";
                $this->flatten($child, $childPath, $blocks, $maxLeafBlocks, $truncated);
            }

            return;
        }

        $blocks[] = ['type' => 'data', 'path' => $path, 'value' => $value];
    }

    protected function typeOf(mixed $value): string
    {
        return match (true) {
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            is_null($value) => 'null',
            default => gettype($value),
        };
    }
}
