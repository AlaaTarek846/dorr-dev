<?php

namespace Modules\AI\Services\Indexing\Concerns;

use Modules\AI\Repositories\AiProviderRepository;

/**
 * Mirrors AiKnowledgeIngestionService::resolveEmbeddingProvider() exactly
 * (same embeddings-capable-provider-key allowlist) rather than
 * duplicating a second, divergent copy of that logic.
 */
trait ResolvesEmbeddingProvider
{
    protected function resolveEmbeddingProvider(AiProviderRepository $providers)
    {
        $provider = $providers->resolveActiveForChat();

        if (! $provider) {
            return null;
        }

        return in_array($provider->key, ['openai'], true) ? $provider : null;
    }
}
