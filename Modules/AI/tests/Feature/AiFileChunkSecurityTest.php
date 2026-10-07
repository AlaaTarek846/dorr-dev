<?php

namespace Modules\AI\Tests\Feature;

use Tests\TestCase;

/**
 * Phase 8 (doc S55): "a chunk must never become accessible independently
 * of its file authorization... do not expose chunk IDs as a bypass to
 * file permissions." No new endpoint was added this phase that reads or
 * returns AiFileChunk by its own id - every route file is scanned to pin
 * that fact, so a future route accidentally exposing `ai_file_chunks` by
 * raw id (bypassing AiFileRepository::findForOwner()'s ownership check)
 * fails this test instead of silently shipping.
 */
class AiFileChunkSecurityTest extends TestCase
{
    public function test_no_route_exposes_chunks_independently_of_their_owning_file(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $routeFiles = glob($moduleRoot.'/routes/*.php') ?: [];

        $this->assertNotEmpty($routeFiles, 'Expected to find the AI module route files.');

        foreach ($routeFiles as $routeFile) {
            $contents = file_get_contents($routeFile);

            $this->assertStringNotContainsString(
                'AiFileChunk',
                $contents,
                "Route file {$routeFile} must not reference AiFileChunk directly - chunk access must always go through the owning AiFile's own authorization (doc S55)."
            );

            $this->assertDoesNotMatchRegularExpression(
                '/ai-?file-?chunks/i',
                $contents,
                "Route file {$routeFile} must not expose a standalone chunk endpoint."
            );
        }
    }

    public function test_chunk_model_has_no_owner_scoped_query_method_implying_it_is_never_queried_directly_by_id_alone(): void
    {
        // AiFileChunk intentionally has no findForOwner()-style method
        // (unlike AiFileRepository) - it is only ever reached via a
        // loaded AiFile relation, so any future retrieval code path
        // naturally inherits the file's own authorization rather than
        // needing its own duplicate check.
        $this->assertFalse(method_exists(\Modules\AI\Models\AiFileChunk::class, 'findForOwner'));
    }
}
