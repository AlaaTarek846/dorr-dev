<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiFeatureFlag;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiFeatureFlagGate;
use Tests\TestCase;

/**
 * v2.0 requirements doc §4.4/§20.1: Feature Flags are a kill switch, not
 * an allowlist - a provider/model/tool with no matching row is allowed
 * by default, and is blocked only by an explicit is_enabled=false row
 * that matches the current environment (and, when set, country/domain).
 */
class AiFeatureFlagGateTest extends TestCase
{
    use RefreshDatabase;

    protected AiFeatureFlagGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AiFeatureFlagGate;
    }

    protected function makeProvider(): AiProvider
    {
        return AiProvider::query()->create(['key' => 'openai', 'name' => 'OpenAI']);
    }

    public function test_provider_is_allowed_by_default_with_no_flag_rows(): void
    {
        $provider = $this->makeProvider();

        $this->assertTrue($this->gate->isProviderAllowed($provider));
    }

    public function test_provider_is_blocked_by_a_matching_global_disable_flag(): void
    {
        $provider = $this->makeProvider();

        AiFeatureFlag::query()->create([
            'key' => 'disable-openai',
            'target_type' => AiFeatureFlag::TARGET_PROVIDER,
            'provider_id' => $provider->id,
            'environment' => app()->environment(),
            'is_enabled' => false,
        ]);

        $this->assertFalse($this->gate->isProviderAllowed($provider));
    }

    public function test_provider_is_not_blocked_by_a_disable_flag_scoped_to_a_different_country(): void
    {
        $provider = $this->makeProvider();

        AiFeatureFlag::query()->create([
            'key' => 'disable-openai-sa',
            'target_type' => AiFeatureFlag::TARGET_PROVIDER,
            'provider_id' => $provider->id,
            'country_code' => 'SA',
            'environment' => app()->environment(),
            'is_enabled' => false,
        ]);

        $this->assertTrue($this->gate->isProviderAllowed($provider, 'EG'));
        $this->assertFalse($this->gate->isProviderAllowed($provider, 'SA'));
    }

    public function test_provider_is_not_blocked_by_a_disable_flag_scoped_to_a_different_environment(): void
    {
        $provider = $this->makeProvider();

        AiFeatureFlag::query()->create([
            'key' => 'disable-openai-staging',
            'target_type' => AiFeatureFlag::TARGET_PROVIDER,
            'provider_id' => $provider->id,
            'environment' => 'staging',
            'is_enabled' => false,
        ]);

        // The current test env is "testing", not "staging", so this flag
        // must not apply here.
        $this->assertTrue($this->gate->isProviderAllowed($provider));
    }

    public function test_model_is_blocked_only_for_the_specific_model_key(): void
    {
        $provider = $this->makeProvider();

        AiFeatureFlag::query()->create([
            'key' => 'disable-gpt-5-mini',
            'target_type' => AiFeatureFlag::TARGET_MODEL,
            'provider_id' => $provider->id,
            'model_key' => 'gpt-5-mini',
            'environment' => app()->environment(),
            'is_enabled' => false,
        ]);

        $this->assertFalse($this->gate->isModelAllowed($provider, 'gpt-5-mini'));
        $this->assertTrue($this->gate->isModelAllowed($provider, 'gpt-5'));
    }

    public function test_tool_is_blocked_by_its_own_disable_flag(): void
    {
        AiFeatureFlag::query()->create([
            'key' => 'disable-web-search',
            'target_type' => AiFeatureFlag::TARGET_TOOL,
            'tool_key' => 'web_search',
            'environment' => app()->environment(),
            'is_enabled' => false,
        ]);

        $this->assertFalse($this->gate->isToolAllowed('web_search'));
        $this->assertTrue($this->gate->isToolAllowed('image_generation'));
    }
}
