<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiGateway as AiGatewayModel;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderDataRule;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyPolicy;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiTrialControl;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Safety\RiskAssessment;
use Modules\AI\Safety\RiskClassifier;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiChatUsageGuard;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiKnowledgeSourceService;
use Modules\AI\Services\AiTaskRunner;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Admin AI settings that used to be stored but never applied, now enforced by the engine (2026-11-11):
 * the personal data policy (consent / external provider), a provider data rule's transformation rules, the
 * "require confirmation" safety action, the routing policy's selection strategy and the built-in routing defaults,
 * plus approve-after-deprecate on knowledge sources and the one-time free trial.
 */
class AiPolicyEnforcementTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();

        // Keep the DORR AI safety classifier on its keyword rules (no extra model call to mock).
        $this->app->instance(RiskClassifier::class, new class($this->app->make(AiGateway::class)) extends RiskClassifier
        {
            public function classify(string $request, ?AiProvider $provider = null): RiskAssessment
            {
                return $this->byRules($request);
            }
        });
    }

    private function personalPolicy(array $switches): AiDataPolicy
    {
        return AiDataPolicy::query()->create($switches + [
            'name' => 'Personal', 'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'consent_required' => false, 'minimization_enabled' => true, 'external_provider_allowed' => true, 'status' => true,
        ]);
    }

    private function applyDataRules(AiProvider $provider, array $messages): array
    {
        $method = new ReflectionMethod(AiGateway::class, 'applyDataRules');
        $method->setAccessible(true);

        return $method->invoke(app(AiGateway::class), $provider, $messages);
    }

    private function profileContext($owner): string
    {
        $method = new ReflectionMethod(AiChatService::class, 'ownerProfileContext');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner);
    }

    // ------------------------------------------------------------- data policy

    public function test_external_provider_not_allowed_masks_personal_data_for_every_provider_and_drops_the_profile(): void
    {
        $provider = $this->usableProvider();
        $owner = $this->chatOwner();
        $turns = [
            ['role' => 'system', 'content' => 'system text ali@example.com'],
            ['role' => 'user', 'content' => 'mail me at ali@example.com'],
        ];

        // No policy: sent as it is, the profile carries the e-mail.
        $this->assertSame($turns, $this->applyDataRules($provider, $turns));
        $this->assertStringContainsString($owner->email, $this->profileContext($owner));

        $policy = $this->personalPolicy(['external_provider_allowed' => false]);

        $sent = $this->applyDataRules($provider, $turns);
        $this->assertSame('mail me at [email]', $sent[1]['content'], 'masked although the provider has no data rule');
        $this->assertSame($turns[0], $sent[0], 'our own system messages are not touched');
        $this->assertStringNotContainsString($owner->email, $this->profileContext($owner));
        $this->assertStringNotContainsString($owner->name, $this->profileContext($owner));

        // Switched off or trashed = not read.
        $policy->update(['status' => false]);
        $this->assertSame($turns, $this->applyDataRules($provider, $turns));
        $policy->update(['status' => true]);
        $policy->delete();
        $this->assertStringContainsString($owner->email, $this->profileContext($owner));
    }

    public function test_consent_required_keeps_the_account_profile_out_of_the_prompt(): void
    {
        $provider = $this->usableProvider();
        $owner = $this->chatOwner();
        $this->personalPolicy(['consent_required' => true]);

        $this->assertStringNotContainsString($owner->email, $this->profileContext($owner));
        // What the person types is theirs to send: not masked for consent alone.
        $turns = [['role' => 'user', 'content' => 'mail me at ali@example.com']];
        $this->assertSame($turns, $this->applyDataRules($provider, $turns));
    }

    // ------------------------------------------------------------- provider data rule transformation rules

    public function test_transformation_rules_mask_only_the_named_kinds_and_redact_terms(): void
    {
        $provider = $this->usableProvider();
        AiProviderDataRule::query()->create([
            'provider_id' => $provider->id, 'sanitize_pii' => false, 'sanitize_secrets' => false, 'status' => true,
            'transformation_rules' => ['mask_fields' => ['email'], 'redact_terms' => ['Project Falcon'], 'unknown' => 1],
        ]);

        $sent = $this->applyDataRules($provider, [['role' => 'user', 'content' => 'ali@example.com +201001234567 about project falcon']]);

        $this->assertSame('[email] +201001234567 about [redacted]', $sent[0]['content']);
    }

    public function test_malformed_transformation_rules_are_refused_by_the_form(): void
    {
        $provider = $this->usableProvider();
        $this->actingAsScreenAdmin('ai-provider-data-rules');

        $this->postJson('/api/admin/v1/ai-provider-data-rules', [
            'provider_id' => $provider->id, 'transformation_rules' => ['mask_fields' => ['passport']],
        ])->assertUnprocessable()->assertJsonValidationErrors('transformation_rules.mask_fields.0');

        $this->postJson('/api/admin/v1/ai-provider-data-rules', [
            'provider_id' => $provider->id, 'transformation_rules' => ['mask_fields' => ['email', 'phone'], 'redact_terms' => ['secret word']],
        ])->assertCreated();
    }

    // ------------------------------------------------------------- safety: require confirmation

    public function test_a_require_confirmation_rule_asks_first_and_the_same_message_again_confirms(): void
    {
        Config::set('ai.chat.verification.enabled', false);
        $this->usableProvider();
        app(AiProviderRepository::class)->setDefault('openai');
        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-confirm', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 60, 'cooldown_minutes' => 0]);
        $policy = AiSafetyPolicy::query()->create(['name' => 'Sensitive', 'risk_level' => 'medium', 'status' => true]);
        $rule = AiSafetyRule::query()->create([
            'safety_policy_id' => $policy->id, 'name' => 'Ask first', 'priority' => 10, 'status' => true,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['transfer all my money']],
            'action' => AiSafetyRule::ACTION_REQUIRE_CONFIRMATION,
        ]);

        $owner = $this->chatOwner();
        $conversation = AiConversation::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'title' => 'Confirm']);

        $calls = 0;
        $this->partialMock(AiGateway::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('chat')->andReturnUsing(function () use (&$calls) {
                $calls++;

                return ['success' => true, 'message' => '', 'content' => 'Done, here is how.'];
            });
        });

        $chat = app(AiChatService::class);
        $first = $chat->sendMessage($owner, $conversation->id, 'Please transfer all my money')->getData(true);

        $this->assertTrue($first['success'], json_encode($first));
        $this->assertSame(0, $calls, 'nothing reaches the AI before the confirmation');
        $this->assertSame(AiRequest::STATUS_CANCELLED, $first['data']['status']);
        $this->assertSame(__('ai.safety_confirmation_required'), $first['data']['assistant_message']['content']);
        $this->assertFalse($first['data']['assistant_message']['is_error']);
        foreach (['user_message', 'assistant_message', 'conversation', 'usage', 'trace_id', 'status', 'confidence', 'sources', 'warnings'] as $key) {
            $this->assertArrayHasKey($key, $first['data'], 'same response keys as any other reply');
        }

        // Another message is not a confirmation: asked again.
        $other = $chat->sendMessage($owner, $conversation->id, 'transfer all my money now!')->getData(true);
        $this->assertSame(AiRequest::STATUS_CANCELLED, $other['data']['status']);

        // The same message again right after the question confirms it.
        $second = $chat->sendMessage($owner, $conversation->id, '  Transfer all my money now! ')->getData(true);
        $this->assertSame(AiRequest::STATUS_COMPLETED, $second['data']['status']);
        $this->assertSame(1, $calls);
        $this->assertSame(3, AiSafetyEvent::query()->where('safety_rule_id', $rule->id)->where('action_taken', AiSafetyRule::ACTION_REQUIRE_CONFIRMATION)->count());

        // A one-shot task has nobody to ask: held back, nothing sent.
        $task = app(AiTaskRunner::class)->chat([['role' => 'user', 'content' => 'transfer all my money']], 'test', $owner, free: true);
        $this->assertFalse($task['success']);
        $this->assertSame(AiTaskRunner::REASON_CONTENT_BLOCKED, $task['reason']);
        $this->assertSame(__('ai.safety_confirmation_unavailable'), $task['message']);
        $this->assertSame(1, $calls);
    }

    // ------------------------------------------------------------- routing: strategy and built-in defaults

    private function policyWithTwoProviders(string $strategy): array
    {
        $openai = $this->usableProvider('openai');
        $anthropic = $this->usableProvider('anthropic');
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'priority' => 1, 'selection_strategy' => $strategy, 'status' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $policy->id, 'provider_id' => $openai->id, 'priority' => 10, 'status' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $policy->id, 'provider_id' => $anthropic->id, 'priority' => 5, 'status' => true]);

        return [$policy, $openai, $anthropic];
    }

    private function firstProviderKey(): string
    {
        return $this->resolveRouting()['candidates'][0]['provider']->key;
    }

    public function test_priority_strategy_keeps_the_configured_order(): void
    {
        $this->policyWithTwoProviders('priority');

        $this->assertSame(['openai', 'openai'], [$this->firstProviderKey(), $this->firstProviderKey()]);
    }

    public function test_round_robin_strategy_takes_turns(): void
    {
        $this->policyWithTwoProviders('round_robin');

        $this->assertSame(['openai', 'anthropic', 'openai'], [$this->firstProviderKey(), $this->firstProviderKey(), $this->firstProviderKey()]);
        $this->assertCount(2, $this->resolveRouting()['candidates'], 'the other one stays as the fallback');
    }

    public function test_latency_optimized_strategy_puts_the_fastest_recent_provider_first(): void
    {
        [, $openai, $anthropic] = $this->policyWithTwoProviders('latency_optimized');
        AiProviderLog::query()->create(['provider_id' => $openai->id, 'response_time_ms' => 2500]);
        AiProviderLog::query()->create(['provider_id' => $anthropic->id, 'response_time_ms' => 400]);
        // A failed call does not count as speed.
        AiProviderLog::query()->create(['provider_id' => $openai->id, 'response_time_ms' => 10, 'error_code' => 'provider_error']);

        $this->assertSame('anthropic', $this->firstProviderKey());
    }

    public function test_a_switched_off_default_policy_falls_back_to_the_built_in_defaults_with_a_warning(): void
    {
        $this->usableProvider();
        app(AiProviderRepository::class)->setDefault('openai');
        $policy = AiRoutingPolicy::query()->create(['name' => 'Default', 'scope_type' => 'country', 'country_code' => 'EG', 'fallback_enabled' => true, 'status' => false]);
        AiGatewayModel::query()->create(['name' => 'Main', 'environment' => 'production', 'default_policy_id' => $policy->id, 'is_active' => true]);
        Config::set('ai.routing.defaults.fallback_enabled', false);
        Log::spy();

        $routing = $this->resolveRouting();

        $this->assertNull($routing['policy'], 'the switched-off policy is respected: its rules are not used');
        $this->assertSame('defaults', $routing['policy_source']);
        $this->assertFalse($routing['fallback_enabled'], 'from config(ai.routing.defaults)');
        $this->assertSame('openai', $routing['candidates'][0]['provider']->key, 'chat still has a provider');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'built-in defaults')
            && ($context['reason'] ?? null) === 'default_policy_inactive');
    }

    // ------------------------------------------------------------- knowledge sources

    public function test_approving_a_deprecated_knowledge_source_makes_it_usable_again(): void
    {
        $source = AiKnowledgeSource::query()->create([
            'owner_type' => 'system', 'name' => 'Guide', 'current_version' => 1, 'is_active' => true,
            'approval_status' => AiKnowledgeSource::APPROVAL_APPROVED, 'processing_status' => AiKnowledgeSource::PROCESSING_READY,
        ]);
        $service = app(AiKnowledgeSourceService::class);

        $this->assertFalse($service->deprecate($source->id)->isRetrievable());

        $approved = $service->approve($source->id);
        $this->assertTrue($approved->is_active);
        $this->assertTrue($approved->isRetrievable());
    }

    // ------------------------------------------------------------- free trial

    public function test_the_free_trial_is_one_time_and_does_not_reopen_after_its_cooldown(): void
    {
        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-once', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 1, 'cooldown_minutes' => 5]);
        $owner = $this->chatOwner();
        $guard = app(AiChatUsageGuard::class);

        $first = $guard->evaluate($owner);
        $this->assertTrue($first['allowed'], json_encode($first['reason']));

        $this->travel(2)->minutes();
        $used = $guard->evaluate($owner);
        $this->assertFalse($used['allowed']);
        $this->assertSame(AiChatUsageGuard::REASON_TRIAL_ENDED, $used['reason']);
        $this->assertSame(AiSubscription::STATUS_EXPIRED, AiSubscription::query()->sole()->status);

        // Well after the plan's cooldown: still ended, no new window and no second trial.
        $this->travel(30)->minutes();
        $this->assertSame(AiChatUsageGuard::REASON_TRIAL_ENDED, $guard->evaluate($owner)['reason']);
        $this->assertFalse($guard->preview($owner)['allowed']);
        $this->assertSame(1, AiSubscription::query()->count());

        // Only the admin can grant it again (trial control: back to "eligible").
        AiTrialControl::query()->where('owner_id', $owner->id)->update(['trial_status' => AiTrialControl::TRIAL_ELIGIBLE]);
        $this->assertTrue($guard->evaluate($owner)['allowed']);
    }

    public function test_a_trial_subscription_left_open_from_before_is_closed_instead_of_reopening(): void
    {
        $plan = AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-legacy', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 1, 'cooldown_minutes' => 5]);
        $owner = $this->chatOwner();
        AiTrialControl::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'trial_status' => AiTrialControl::TRIAL_ENDED, 'abuse_status' => AiTrialControl::ABUSE_CLEAR]);
        AiSubscription::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'plan_id' => $plan->id, 'starts_at' => now()->subDay(), 'status' => AiSubscription::STATUS_ACTIVE]);

        $guard = app(AiChatUsageGuard::class);
        $this->assertFalse($guard->preview($owner)['allowed']);
        $this->assertSame(AiChatUsageGuard::REASON_TRIAL_ENDED, $guard->evaluate($owner)['reason']);
        $this->assertSame(AiSubscription::STATUS_EXPIRED, AiSubscription::query()->sole()->status);
    }
}
