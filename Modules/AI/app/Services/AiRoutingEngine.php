<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AiGateway as AiGatewayModel;
use Modules\AI\Models\AiIntent;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Repositories\AiProviderRepository;
use Throwable;

/**
 * The real per-request routing engine (Phase 4 tables) that decides which
 * AI provider/model should actually answer a given chat message, instead
 * of every message always going to a single "active" provider:
 *
 * 1. Classify the message into an ai_intents row (AiIntentClassifier).
 * 2. Pick the best-matching active ai_routing_policies row for this owner
 *    (global, or scoped to their country/plan).
 * 3. Within that policy, take its ai_routing_rules ordered by priority,
 *    preferring rules that target this exact intent over generic
 *    (intent_id = null) ones, and keep only providers that are actually
 *    enabled and have an API key configured.
 * 4. If nothing is configured yet (no rules, or none of them usable),
 *    fall back to the single active/default provider - so chat keeps
 *    working the moment one provider is connected, even before an admin
 *    has set up any routing rules.
 *
 * The first candidate returned is who gets the message; the rest is the
 * ordered fallback chain AiChatService walks through (and logs as
 * ai_failovers, Phase 9) if a candidate's request actually fails.
 */
class AiRoutingEngine
{
    public function __construct(
        protected AiIntentClassifier $intentClassifier,
        protected AiFeatureFlagGate $featureFlags,
        protected AiRequiredCapabilityResolver $requiredCapabilities,
        protected AiModelResolver $modelResolver,
    ) {}

    /**
     * @return array{
     *     intent: ?AiIntent,
     *     gateway: ?AiGatewayModel,
     *     policy: ?AiRoutingPolicy,
     *     candidates: list<array{provider: AiProvider, model_key: ?string, capability_matched?: bool}>,
     *     required_capabilities: list<string>,
     *     fallback_enabled: bool,
     * }
     */
    public function resolve(Authenticatable $owner, string $content, ?int $planId, AiProviderRepository $providers, ?string $attachmentMimeType = null, bool $recentImageExists = false, array $extraCapabilities = []): array
    {
        $intent = $this->intentClassifier->classify($content);
        $requiredCapabilities = $this->requiredCapabilities->resolve($content, $attachmentMimeType, $recentImageExists);

        // Capabilities the smart intent router (AiIntentRouterService) found for
        // phrasings the static dictionary did not know. An image request must
        // not also demand "vision" (see AiRequiredCapabilityResolver's note).
        if ($extraCapabilities !== []) {
            $requiredCapabilities = array_values(array_unique(array_merge($requiredCapabilities, $extraCapabilities)));

            if (in_array('image_generation', $extraCapabilities, true)) {
                $requiredCapabilities = array_values(array_diff($requiredCapabilities, ['vision']));
            }
        }

        // Switched off or in the trash (SoftDeletes' default scope) = not used - here and for policies / rules below.
        $gateway = AiGatewayModel::query()
            ->where('environment', 'production')
            ->where('status', true)
            ->with('defaultPolicy')
            ->orderBy('id')
            ->first();

        $countryCode = null;

        if (method_exists($owner, 'country')) {
            $countryCode = $owner->country()->value('code');
        }

        // The gateway's default policy is shown even when trashed (the relation is withTrashed for the admin screen),
        // so the fallback only takes it while it is switched on and not in the trash.
        $defaultPolicy = $gateway?->defaultPolicy;
        $defaultPolicySkipped = false;

        if ($defaultPolicy !== null && (! $defaultPolicy->status || $defaultPolicy->trashed())) {
            $defaultPolicy = null;
            $defaultPolicySkipped = true;
        }

        $policy = AiRoutingPolicy::query()
            ->where('status', true)
            ->orderByDesc('priority')
            ->get()
            ->first(fn (AiRoutingPolicy $candidate) => match ($candidate->scope_type) {
                'global' => true,
                'country' => $countryCode !== null && $candidate->country_code === $countryCode,
                'plan' => $planId !== null && $candidate->plan_id === $planId,
                // "service" / "custom" scopes need context a plain chat
                // message doesn't carry (no service/booking is being acted
                // on here), so they are skipped rather than guessed at.
                default => false,
            }) ?? $defaultPolicy;

        $candidates = collect();

        if ($policy) {
            $rules = AiRoutingRule::query()
                ->where('routing_policy_id', $policy->id)
                ->where('status', true)
                ->with('provider')
                ->orderByDesc('priority')
                ->get();

            $usable = fn (AiRoutingRule $rule) => $rule->provider
                && $rule->provider->isUsableForChat()
                && $this->featureFlags->isProviderAllowed($rule->provider, $countryCode, $intent?->key)
                && (! $rule->model_key || $this->featureFlags->isModelAllowed($rule->provider, $rule->model_key, $countryCode, $intent?->key));

            // Rules for this exact intent first, then the generic ones; the policy's selection strategy orders each
            // group (priority = as configured).
            $groups = [
                $rules->filter(fn (AiRoutingRule $rule) => $intent && $rule->intent_id === $intent->id)->filter($usable)->values(),
                $rules->filter(fn (AiRoutingRule $rule) => $rule->intent_id === null)->filter($usable)->values(),
            ];

            foreach ($groups as $index => $group) {
                foreach ($this->applySelectionStrategy($policy, $group, $index) as $rule) {
                    $candidates->push(['provider' => $rule->provider, 'model_key' => $rule->model_key ?: null]);
                }
            }
        } else {
            // Never "no policy": with no usable admin policy (none matches, or the gateway's default was switched off
            // or trashed - and that choice is respected, its rules are not used) the built-in defaults apply: the
            // active provider, with config('ai.routing.*') fallback and strategy.
            Log::warning('AI routing: no active routing policy matched; using the built-in defaults', [
                'reason' => $defaultPolicySkipped ? 'default_policy_inactive' : 'no_matching_policy',
                'gateway_id' => $gateway?->id,
                'default_policy_id' => $gateway?->default_policy_id,
            ]);
        }

        if ($candidates->isEmpty()) {
            $fallbackProvider = $providers->resolveActiveForChat();

            if ($fallbackProvider && $this->featureFlags->isProviderAllowed($fallbackProvider, $countryCode, $intent?->key)) {
                $candidates->push(['provider' => $fallbackProvider, 'model_key' => null]);
            }
        }

        $candidates = $this->applyCapabilityPreferences($candidates, $requiredCapabilities, $providers, $countryCode, $intent);

        return [
            'intent' => $intent,
            'gateway' => $gateway,
            'policy' => $policy,
            // 'admin' = an ai_routing_policies row; 'defaults' = none usable, the built-in config applies.
            'policy_source' => $policy ? 'admin' : 'defaults',
            'candidates' => $this->uniqueByProvider($candidates)->values()->all(),
            'required_capabilities' => $requiredCapabilities,
            'fallback_enabled' => $policy
                ? (bool) ($policy->fallback_enabled ?? true)
                : (bool) config('ai.routing.defaults.fallback_enabled', true),
        ];
    }

    /** The strategies the engine knows; anything else (an old or unknown value) is treated as "priority". */
    public const STRATEGIES = ['priority', 'round_robin', 'latency_optimized'];

    /**
     * Orders a group of usable rules by the policy's selection_strategy (fail-safe: any problem keeps the
     * configured priority order):
     * - priority (default): highest rule priority first, as configured.
     * - round_robin: the same rules, rotated by one on every message the policy routes, so the first choice takes
     *   turns (a counter per policy and group in the cache).
     * - latency_optimized: the provider with the lowest average response time over the last 24 hours of successful
     *   calls (ai_provider_logs) first; providers with no recent calls keep their priority order after them.
     *
     * @param  Collection<int, AiRoutingRule>  $rules
     * @return Collection<int, AiRoutingRule>
     */
    protected function applySelectionStrategy(AiRoutingPolicy $policy, Collection $rules, int $group): Collection
    {
        if ($rules->count() < 2) {
            return $rules;
        }

        $strategy = $policy->selection_strategy ?: (string) config('ai.routing.defaults.selection_strategy', 'priority');

        try {
            return match ($strategy) {
                'round_robin' => $this->rotate($rules, (int) Cache::increment("ai:routing:round-robin:{$policy->id}:{$group}") - 1),
                'latency_optimized' => $this->byLatency($rules),
                default => $rules,
            };
        } catch (Throwable $e) {
            Log::warning('AI routing: selection strategy failed; using priority order', [
                'policy_id' => $policy->id, 'strategy' => $strategy, 'error' => $e->getMessage(),
            ]);

            return $rules;
        }
    }

    /**
     * @param  Collection<int, AiRoutingRule>  $rules
     * @return Collection<int, AiRoutingRule>
     */
    protected function rotate(Collection $rules, int $turn): Collection
    {
        $offset = (($turn % $rules->count()) + $rules->count()) % $rules->count();

        return $rules->slice($offset)->concat($rules->slice(0, $offset))->values();
    }

    /**
     * @param  Collection<int, AiRoutingRule>  $rules
     * @return Collection<int, AiRoutingRule>
     */
    protected function byLatency(Collection $rules): Collection
    {
        $averages = AiProviderLog::query()
            ->whereIn('provider_id', $rules->pluck('provider_id')->unique()->all())
            ->where('created_at', '>=', now()->subDay())
            ->whereNull('error_code')
            ->whereNotNull('response_time_ms')
            ->groupBy('provider_id')
            ->selectRaw('provider_id, AVG(response_time_ms) as average_ms')
            ->pluck('average_ms', 'provider_id');

        // Stable: measured providers by speed, then the unmeasured ones in priority order.
        return $rules
            ->values()
            ->map(fn (AiRoutingRule $rule, int $position) => [$rule, $averages->get($rule->provider_id), $position])
            ->sort(fn (array $a, array $b) => [$a[1] === null ? 1 : 0, (float) $a[1], $a[2]] <=> [$b[1] === null ? 1 : 0, (float) $b[1], $b[2]])
            ->map(fn (array $row) => $row[0])
            ->values();
    }

    /**
     * Prefers, among the already-resolved candidates, whichever one has a
     * registered ai_provider_models row matching every required capability
     * (Phase 1 multi-model registry) - moving it to the front of the
     * fallback chain and pointing model_key at that specific model instead
     * of whatever a routing rule or the provider's legacy single $model
     * column would have picked.
     *
     * If a capability is required but none of the routing-derived
     * candidates can do it, this also searches every other enabled,
     * usable provider (outside the current routing policy) for one that
     * can, and adds it as an extra first candidate - "auto-pick the best
     * model I actually have connected" per the business requirement,
     * rather than staying limited to whatever routing rules an admin
     * happened to configure. When still nothing matches anywhere, the
     * original candidates are left untouched so chat degrades gracefully
     * to a best-effort plain-text reply instead of failing outright.
     *
     * @param  Collection<int, array{provider: AiProvider, model_key: ?string}>  $candidates
     * @param  list<string>  $requiredCapabilities
     * @return Collection<int, array{provider: AiProvider, model_key: ?string}>
     */
    protected function applyCapabilityPreferences(
        Collection $candidates,
        array $requiredCapabilities,
        AiProviderRepository $providers,
        ?string $countryCode,
        ?AiIntent $intent,
    ): Collection {
        $upgraded = $candidates->map(function (array $candidate) use ($requiredCapabilities) {
            $provider = $candidate['provider'];
            $bestMatch = $requiredCapabilities !== []
                ? $provider->bestModelFor($requiredCapabilities)
                : $provider->defaultRegisteredModel();

            if ($bestMatch) {
                $candidate['model_key'] = $bestMatch->model_key;
                $candidate['capability_matched'] = $requiredCapabilities !== [] && $bestMatch->hasAllCapabilities($requiredCapabilities);
            } else {
                $candidate['capability_matched'] = false;
            }

            return $candidate;
        });

        if ($requiredCapabilities === []) {
            return $upgraded;
        }

        if ($upgraded->contains('capability_matched', true)) {
            // Stable-sort: matched candidates first, original relative
            // order preserved within each group (so routing priority still
            // breaks ties among equally-capable candidates).
            return $upgraded->sortByDesc('capability_matched')->values();
        }

        $alreadyConsidered = $upgraded->pluck('provider.id')->all();

        // Delegates to AiModelResolver (Dynamic Model Registry, section
        // 18) - this used to be its own hand-written provider scan; see
        // that class's docblock for why it is now the one shared
        // implementation instead of a second copy of the same loop.
        $externalMatch = $this->modelResolver->resolve(
            $providers,
            $requiredCapabilities,
            $alreadyConsidered,
            fn (AiProvider $provider) => $this->featureFlags->isProviderAllowed($provider, $countryCode, $intent?->key),
        );

        if ($externalMatch) {
            $upgraded->prepend([
                'provider' => $externalMatch['provider'],
                'model_key' => $externalMatch['model']->model_key,
                'capability_matched' => true,
            ]);
        }

        return $upgraded;
    }

    /**
     * @param  Collection<int, array{provider: AiProvider, model_key: ?string}>  $candidates
     * @return Collection<int, array{provider: AiProvider, model_key: ?string}>
     */
    protected function uniqueByProvider(Collection $candidates): Collection
    {
        return $candidates->unique(fn (array $candidate) => $candidate['provider']->id);
    }
}
