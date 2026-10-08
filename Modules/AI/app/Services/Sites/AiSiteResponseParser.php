<?php

namespace Modules\AI\Services\Sites;

use Modules\AI\Exceptions\AiSiteException;

/**
 * Reads the model's answer: any number of
 *
 *   === FILE: path ===
 *   ...content...
 *   === END FILE ===
 *
 * blocks plus optional "=== DELETE: path ===" lines. Anything outside the
 * blocks is ignored. A block that never closes means the answer was cut off,
 * which is refused rather than saved half-written.
 */
class AiSiteResponseParser
{
    /**
     * @return array{files: array<string, string>, deletes: list<string>}
     */
    public function parse(string $answer): array
    {
        $files = [];
        $deletes = [];
        $lines = preg_split('/\R/u', $answer) ?: [];
        $current = null;
        $buffer = [];

        foreach ($lines as $line) {
            if ($current === null) {
                if (preg_match('/^\s*===\s*FILE:\s*(.+?)\s*===\s*$/u', $line, $m) === 1) {
                    $current = trim($m[1]);
                    $buffer = [];
                } elseif (preg_match('/^\s*===\s*DELETE:\s*(.+?)\s*===\s*$/u', $line, $m) === 1) {
                    $deletes[] = trim($m[1]);
                }

                continue;
            }

            if (preg_match('/^\s*===\s*END FILE\s*===\s*$/u', $line) === 1) {
                $files[$current] = $this->stripFence(implode("\n", $buffer));
                $current = null;

                continue;
            }

            $buffer[] = $line;
        }

        if ($current !== null) {
            throw new AiSiteException('generation_truncated');
        }

        if ($files === [] && $deletes === []) {
            throw new AiSiteException('generation_empty');
        }

        return ['files' => $files, 'deletes' => $deletes];
    }

    /** Models often wrap a file in a ```lang fence even when told not to. */
    protected function stripFence(string $content): string
    {
        $trimmed = trim($content);

        if (preg_match('/\A```[A-Za-z0-9_-]*\R(.*)\R```\z/su', $trimmed, $m) === 1) {
            return $m[1]."\n";
        }

        return rtrim($content)."\n";
    }
}
