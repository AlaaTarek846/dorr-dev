<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Cache;
use Modules\AI\Models\AiFeatureFlag;
use Modules\AI\Models\AiProvider;

/**
 * The runtime check behind Feature Flags (v2.0 doc, 4.4): lets the admin
 * turn a provider, model or tool off for a specific country/domain/
 * environment from a settings screen, with no code deploy. Flags are a
 * kill switch, not an allowlist - a provider with no matching flag row is
 * allowed by default; it is blocked only when an explicit is_enabled=false
 * flag matches the current country/domain/environment (or applies
 * globally via null scope fields).
 */
class AiFeatureFlagGate
{
    protected const CACHE_TTL = 30;

    public function isProviderAllowed(AiProvider $provider, ?string $countryCode = null, ?string $domain = null): bool
    {
        return ! $this->hasMatchingDisableFlag(AiFeatureFlag::TARGET_PROVIDER, $provider->id, null, null, $countryCode, $domain);
    }

    public function isModelAllowed(AiProvider $provider, string $modelKey, ?string $countryCode = null, ?string $domain = null): bool
    {
        return ! $this->hasMatchingDisableFlag(AiFeatureFlag::TARGET_MODEL, $provider->id, $modelKey, null, $countryCode, $domain);
    }

    public function isToolAllowed(string $toolKey, ?string $countryCode = null, ?string $domain = null): bool
    {
        return ! $this->hasMatchingDisableFlag(AiFeatureFlag::TARGET_TOOL, null, null, $toolKey, $countryCode, $domain);
    }

    protected function hasMatchingDisableFlag(
        string $targetType,
        ?int $providerId,
        ?string $modelKey,
        ?string $toolKey,
        ?string $countryCode,
        ?string $domain,
    ): bool {
        $environment = config('app.env', 'production');

        $flags = $this->disabledFlagsFor($targetType);

        foreach ($flags as $flag) {
            if ($flag['environment'] !== $environment) {
                continue;
            }

            if ($providerId !== null && $flag['provider_id'] !== null && $flag['provider_id'] !== $providerId) {
                continue;
            }

            if ($modelKey !== null && $flag['model_key'] !== null && $flag['model_key'] !== $modelKey) {
                continue;
            }

            if ($toolKey !== null && $flag['tool_key'] !== null && $flag['tool_key'] !== $toolKey) {
                continue;
            }

            if ($flag['country_code'] !== null && $flag['country_code'] !== $countryCode) {
                continue;
            }

            if ($flag['domain'] !== null && $flag['domain'] !== $domain) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Short-lived cache (30s) so a single chat request doesn't run this
     * query per candidate provider, while an admin toggling a flag still
     * takes effect within seconds rather than needing a cache clear.
     *
     * @return list<array{provider_id: ?int, model_key: ?string, tool_key: ?string, country_code: ?string, domain: ?string, environment: string}>
     */
    protected function disabledFlagsFor(string $targetType): array
    {
        return Cache::remember("ai_feature_flags_disabled_{$targetType}", self::CACHE_TTL, function () use ($targetType) {
            return AiFeatureFlag::query()
                ->where('target_type', $targetType)
                ->where('is_enabled', false)
                ->get(['provider_id', 'model_key', 'tool_key', 'country_code', 'domain', 'environment'])
                ->map(fn (AiFeatureFlag $flag) => $flag->only(['provider_id', 'model_key', 'tool_key', 'country_code', 'domain', 'environment']))
                ->all();
        });
    }
}
