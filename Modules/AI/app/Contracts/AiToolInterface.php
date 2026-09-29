<?php

namespace Modules\AI\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Master spec section 12/48-49: "Tools are not models" - Web Search /
 * File Search / an internal action like this are capabilities/tools, not
 * chat models, and every tool must have a name, description, schema,
 * authorization, execution and result.
 *
 * This is a first, real slice of that architecture (see AiToolResolver's
 * docblock for exactly what it does and does not do yet): tools are
 * triggered by a deterministic keyword match on the user's message
 * (AiToolResolver), not by the model itself deciding mid-generation via
 * OpenAI's own function-calling `tools` array - that would need a second
 * round trip to the provider with the tool result appended to the
 * conversation, which no connector implements yet. What IS real here:
 * a tool with its own schema, an explicit per-owner authorization check,
 * and genuine execution against real application data (see
 * AiUsageStatusTool) - not a stub that only returns a canned string.
 */
interface AiToolInterface
{
    /**
     * Stable machine name, e.g. "usage_status". Used by AiToolResolver's
     * trigger map and to look the tool up in AiToolRegistry.
     */
    public function name(): string;

    /**
     * Human-readable description - what this tool does, for admin/debug
     * surfaces and for anywhere the schema is later handed to a model's
     * own function-calling `tools` array.
     */
    public function description(): string;

    /**
     * JSON-schema-shaped parameter description (OpenAI function-calling
     * convention: {type: object, properties: {...}, required: [...]}) -
     * kept even though nothing calls the model's native tool-use loop
     * yet, so a tool defined against this interface is already shaped
     * correctly for that upgrade.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Whether $owner may invoke this tool at all. Master spec section 48:
     * "Do NOT allow AI to execute arbitrary destructive actions without
     * authorization" and section 49: "AI must only access data the
     * authenticated user is allowed to access." A tool with no owner
     * restriction (e.g. a pure calculation) still returns true here
     * explicitly rather than skipping the check.
     */
    public function authorize(Authenticatable $owner): bool;

    /**
     * Runs the tool and returns a plain, JSON-serializable result array.
     * Never called unless authorize() returned true. Implementations
     * must not throw for an expected/ordinary failure (e.g. "nothing
     * found") - return a result array describing that instead, the same
     * way the rest of this module treats "unsupported" as data, not an
     * exception (AbstractHttpConnector's default capability responses).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(Authenticatable $owner, array $arguments): array;
}
