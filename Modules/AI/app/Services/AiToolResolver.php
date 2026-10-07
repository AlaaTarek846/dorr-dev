<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;

/**
 * Master spec section 12: tools are not models - "Web Search / File
 * Search / Code Interpreter / Computer Use / Functions... determine
 * whether the request requires" one of them. Web Search and (document)
 * File Search already have their own dedicated, tested pipelines
 * (AiRequiredCapabilityResolver's web_search/document_analysis entries);
 * this resolver is for everything else routed through the newer
 * AiToolInterface/AiToolRegistry architecture (section 48-49) - an
 * internal action against the application's own data, not a model
 * capability.
 *
 * Same deliberate trade-off as AiRequiredCapabilityResolver: a
 * transparent, admin-editable keyword map (config('ai.tools.triggers')),
 * not a black-box classifier or the model's own native function-calling
 * loop - see AiToolInterface's docblock for exactly what that means and
 * what a future upgrade would add.
 */
class AiToolResolver
{
    /**
     * Tool name(s) whose trigger phrases matched $content, in the order
     * config('ai.tools.triggers') lists them. Usually at most one match
     * matters in practice (AiChatService only acts on the first), but
     * every match is returned so a caller can decide.
     *
     * @return list<string>
     */
    public function resolve(string $content): array
    {
        if (! (bool) config('ai.tools.enabled', true)) {
            return [];
        }

        $lower = mb_strtolower($content);
        $matched = [];

        foreach ((array) config('ai.tools.triggers', []) as $toolName => $phrases) {
            foreach ((array) $phrases as $phrase) {
                if (Str::contains($lower, mb_strtolower((string) $phrase))) {
                    $matched[] = (string) $toolName;
                    break;
                }
            }
        }

        return $matched;
    }
}
