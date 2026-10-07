<?php

namespace Modules\AI\Services;

use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * Dynamic Model Registry, section 18: "find the first registered model
 * across ALL providers that has these capabilities" used to be written
 * twice, independently, by two different fixes at two different points
 * in this session:
 *
 * - AiRoutingEngine::applyCapabilityPreferences()'s "external match"
 *   fallback (when none of the routing-derived candidates has the
 *   required capability, search every OTHER usable, feature-flag-allowed
 *   provider for one that does).
 * - AiChatService::resolveCandidateFor() (added for speech-to-text/
 *   text-to-speech: find a provider whose registered models include a
 *   given single capability, deliberately independent of per-turn
 *   routing).
 *
 * Both walk AiProviderRepository::all() in the same order, apply the
 * same AiProvider::isUsableForChat() gate, and call the same
 * AiProvider::bestModelFor()/defaultRegisteredModel(). This class is that
 * one real implementation; both call sites now delegate to it instead of
 * keeping their own copy in sync by hand.
 */
class AiModelResolver
{
    /**
     * @param  list<string>  $requiredCapabilities  Empty means "any usable provider's default model".
     * @param  list<int>  $excludeProviderIds  Providers already considered elsewhere - skipped here.
     * @param  ?callable(AiProvider): bool  $providerFilter  Extra predicate (e.g. a feature-flag/country/intent allowance) a candidate provider must also pass.
     * @return array{provider: AiProvider, model: \Modules\AI\Models\AiProviderModel}|null
     */
    public function resolve(
        AiProviderRepository $providers,
        array $requiredCapabilities = [],
        array $excludeProviderIds = [],
        ?callable $providerFilter = null,
    ): ?array {
        foreach ($providers->all() as $provider) {
            if (in_array($provider->id, $excludeProviderIds, true)) {
                continue;
            }

            if (! $provider->isUsableForChat()) {
                continue;
            }

            if ($providerFilter !== null && ! $providerFilter($provider)) {
                continue;
            }

            $model = $requiredCapabilities !== []
                ? $provider->bestModelFor($requiredCapabilities)
                : $provider->defaultRegisteredModel();

            if ($model !== null) {
                return ['provider' => $provider, 'model' => $model];
            }
        }

        return null;
    }
}
