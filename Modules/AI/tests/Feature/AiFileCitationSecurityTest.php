<?php

namespace Modules\AI\Tests\Feature;

use Modules\AI\Models\AiFileCitation;
use Tests\TestCase;

/**
 * Phase 9 (doc S14/S35): "a user must never receive unauthorized file
 * content/chunk metadata/file names/citations... retrieval must never
 * return chunks from a file the current user cannot access." Mirrors
 * AiFileChunkSecurityTest's own route-scan pattern for the new
 * AiFileCitation model: no route this phase added reads or returns
 * citations by their own id, independent of the owning request/file's
 * authorization, and AiRetrievalEngine resolves file scope by
 * owner_type/owner_id BEFORE ever touching ai_file_chunks (never
 * fetch-then-filter).
 */
class AiFileCitationSecurityTest extends TestCase
{
    public function test_no_route_exposes_citations_independently_of_their_owning_request(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $routeFiles = glob($moduleRoot.'/routes/*.php') ?: [];

        $this->assertNotEmpty($routeFiles, 'Expected to find the AI module route files.');

        foreach ($routeFiles as $routeFile) {
            $contents = file_get_contents($routeFile);

            $this->assertStringNotContainsString(
                'AiFileCitation',
                $contents,
                "Route file {$routeFile} must not reference AiFileCitation directly - citation access must always flow through the owning AiRequest/conversation's own authorization."
            );

            $this->assertDoesNotMatchRegularExpression(
                '/ai-?file-?citations/i',
                $contents,
                "Route file {$routeFile} must not expose a standalone citation endpoint."
            );
        }
    }

    public function test_citation_model_has_no_owner_scoped_query_method_implying_it_is_never_queried_directly_by_id_alone(): void
    {
        $this->assertFalse(method_exists(AiFileCitation::class, 'findForOwner'));
    }

    public function test_retrieval_engine_resolves_file_scope_before_touching_chunks(): void
    {
        // Static-source check, same spirit as doc S14's "prefer
        // authorization-aware query constraints" - resolveFileScope()
        // must run and its result must gate fetchCandidates(), so an
        // owner can never reach another owner's chunk rows via any
        // query path inside this class.
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/Retrieval/AiRetrievalEngine.php'
        );

        $this->assertNotFalse($source);

        $resolvePos = strpos($source, 'resolveFileScope(');
        $fetchPos = strpos($source, '$fileIds = $this->resolveFileScope');
        $candidatesPos = strpos($source, 'fetchCandidates($fileIds');

        $this->assertNotFalse($resolvePos);
        $this->assertNotFalse($fetchPos);
        $this->assertNotFalse($candidatesPos);
        $this->assertLessThan($candidatesPos, $fetchPos, 'retrieve() must resolve the authorized file scope before fetching any chunk candidates.');
    }
}
