<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiGateway as AiGatewayModel;
use Modules\AI\Models\AiIntent;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Repositories\AiProviderRepository;

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
    ) {}

    /**
     * @return array{
     *     intent: ?AiIntent,
     *     gateway: ?AiGatewayModel,
     *     policy: ?AiRoutingPolicy,
     *     candidates: list<array{provider: AiProvider, model_key: ?string}>,
     *     fallback_enabled: bool,
     * }
     */
    public function resolve(Authenticatable $owner, string $content, ?int $planId, AiProviderRepository $providers): array
    {
        $intent = $this->intentClassifier->classify($content);

        $gateway = AiGatewayModel::query()
            ->where('environment', 'production')
            ->where('is_active', true)
            ->with('defaultPolicy')
            ->orderBy('id')
            ->first();

        $countryCode = null;

        if (method_exists($owner, 'country')) {
            $countryCode = $owner->country()->value('code');
        }

        $policy = AiRoutingPolicy::query()
            ->where('is_active', true)
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
            }) ?? $gateway?->defaultPolicy;

        $candidates = collect();

        if ($policy) {
            $rules = AiRoutingRule::query()
                ->where('routing_policy_id', $policy->id)
                ->where('is_active', true)
                ->with('provider')
                ->orderByDesc('priority')
                ->get();

            $ordered = $rules
                ->filter(fn (AiRoutingRule $rule) => $intent && $rule->intent_id === $intent->id)
                ->concat($rules->filter(fn (AiRoutingRule $rule) => $rule->intent_id === null));

            foreach ($ordered as $rule) {
                if (
                    $rule->provider
                    && $rule->provider->isUsableForChat()
                    && $this->featureFlags->isProviderAllowed($rule->provider, $countryCode, $intent?->key)
                    && (! $rule->model_key || $this->featureFlags->isModelAllowed($rule->provider, $rule->model_key, $countryCode, $intent?->key))
                ) {
                    $candidates->push(['provider' => $rule->provider, 'model_key' => $rule->model_key ?: null]);
                }
            }
        }

        if ($candidates->isEmpty()) {
            $fallbackProvider = $providers->resolveActiveForChat();

            if ($fallbackProvider && $this->featureFlags->isProviderAllowed($fallbackProvider, $countryCode, $intent?->key)) {
                $candidates->push(['provider' => $fallbackProvider, 'model_key' => null]);
            }
        }

        return [
            'intent' => $intent,
            'gateway' => $gateway,
            'policy' => $policy,
            'candidates' => $this->uniqueByProvider($candidates)->values()->all(),
            'fallback_enabled' => $policy->fallback_enabled ?? true,
        ];
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
