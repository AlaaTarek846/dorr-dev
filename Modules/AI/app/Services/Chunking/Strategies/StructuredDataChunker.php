<?php

namespace Modules\AI\Services\Chunking\Strategies;

use Modules\AI\Services\Chunking\AiChunkDraft;
use Modules\AI\Services\Chunking\AiChunkerInterface;

/**
 * Phase 8 (doc S22/S23): JsonFileProcessor/XmlFileProcessor already
 * normalize to a flat list of {'type' => 'data', 'path' => ..., 'value'
 * / other fields} blocks (confirmed by inspection - neither processor
 * is re-parsed here). This strategy groups those path-addressed
 * fragments into chunks by object/array boundary rather than ever
 * flattening the whole document into one giant string.
 */
class StructuredDataChunker implements AiChunkerInterface
{
    public function supports(array $normalizedContent): bool
    {
        $type = $normalizedContent['document_type'] ?? null;

        if (in_array($type, ['json', 'xml'], true)) {
            return true;
        }

        $blocks = $normalizedContent['blocks'] ?? [];

        return $blocks !== [] && ($blocks[0]['type'] ?? null) === 'data';
    }

    public function chunk(array $normalizedContent): array
    {
        $blocks = $normalizedContent['blocks'] ?? [];
        $maxCharacters = (int) config('ai.chunking.structured.max_characters', 2000);
        $groupDepth = (int) config('ai.chunking.structured.group_by_depth', 1);

        if ($blocks === []) {
            return [];
        }

        $groups = [];

        foreach ($blocks as $block) {
            $path = (string) ($block['path'] ?? '');
            $groupKey = $this->groupKeyForPath($path, $groupDepth);
            $groups[$groupKey][] = $block;
        }

        $drafts = [];
        $index = 0;

        foreach ($groups as $groupKey => $groupBlocks) {
            $lines = [];

            foreach ($groupBlocks as $block) {
                $value = $block['value'] ?? ($block['content'] ?? null);
                $encoded = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);

                $lines[] = ($block['path'] ?? $groupKey).': '.$encoded;
            }

            foreach (array_chunk($lines, max(1, (int) ceil($maxCharacters / 80))) as $lineGroup) {
                $text = implode("\n", $lineGroup);

                if (mb_strlen($text) > $maxCharacters) {
                    // A single oversized value (e.g. one very long
                    // string field) - split on character boundary as a
                    // last resort rather than ever dropping it.
                    foreach (mb_str_split($text, $maxCharacters) as $piece) {
                        $drafts[] = new AiChunkDraft($index++, $piece, 'data', ['path' => $groupKey, 'depth' => $groupDepth]);
                    }

                    continue;
                }

                $drafts[] = new AiChunkDraft($index++, $text, 'data', ['path' => $groupKey, 'depth' => $groupDepth]);
            }
        }

        return $drafts;
    }

    /**
     * Groups "customers[0].orders[3].total" under "customers[0]" (depth
     * 1) so a chunk corresponds to one logical object/array element,
     * never the whole document as one blob and never one chunk per
     * scalar leaf.
     */
    private function groupKeyForPath(string $path, int $depth): string
    {
        if ($path === '') {
            return 'root';
        }

        preg_match_all('/[^.\[\]]+(\[\d+\])?/', $path, $matches);
        $segments = $matches[0] ?? [];

        if ($segments === []) {
            return $path;
        }

        return implode('.', array_slice($segments, 0, max(1, $depth)));
    }
}
