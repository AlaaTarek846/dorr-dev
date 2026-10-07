<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderDataRule;
use Modules\AI\Services\AiGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2.0 requirements doc S17.2/17.4: outgoing PII/secret data-leakage
 * prevention at the actual network boundary - AiGateway::applyDataRules()
 * - which strips PII/secrets from user/assistant turns before they leave
 * to an external provider, per that provider's own ai_provider_data_rules
 * row. Reached via reflection because applyDataRules() is intentionally
 * protected (an internal step of chat()/embed(), not a public API), and
 * calling the real chat() would require a live network connector this
 * environment cannot reach - reflection lets the actual sanitization
 * logic run unmocked while the network call itself never happens.
 */
class AiGatewayDataSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProvider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'Data-rule test provider',
            'is_enabled' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function applyDataRules(AiProvider $provider, array $messages): array
    {
        $method = new ReflectionMethod(AiGateway::class, 'applyDataRules');
        $method->setAccessible(true);

        return $method->invoke(app(AiGateway::class), $provider, $messages);
    }

    public function test_pii_is_redacted_from_user_messages_when_the_providers_data_rule_requires_it(): void
    {
        $provider = $this->makeProvider();

        AiProviderDataRule::query()->create([
            'provider_id' => $provider->id,
            'sanitize_pii' => true,
            'sanitize_secrets' => false,
            'is_active' => true,
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'My email is ali@example.com, please help.'],
        ];

        $result = $this->applyDataRules($provider, $messages);

        $this->assertStringNotContainsString('ali@example.com', $result[0]['content']);
        $this->assertStringContainsString('[email]', $result[0]['content']);
    }

    public function test_secrets_are_redacted_from_user_messages_when_the_providers_data_rule_requires_it(): void
    {
        $provider = $this->makeProvider();

        AiProviderDataRule::query()->create([
            'provider_id' => $provider->id,
            'sanitize_pii' => false,
            'sanitize_secrets' => true,
            'is_active' => true,
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'Authorization: Bearer abcdef1234567890.xyz1234567890'],
        ];

        $result = $this->applyDataRules($provider, $messages);

        $this->assertStringContainsString('[secret]', $result[0]['content']);
        $this->assertStringNotContainsString('abcdef1234567890', $result[0]['content']);
    }

    public function test_the_system_prompt_message_is_never_sanitized(): void
    {
        $provider = $this->makeProvider();

        AiProviderDataRule::query()->create([
            'provider_id' => $provider->id,
            'sanitize_pii' => true,
            'sanitize_secrets' => true,
            'is_active' => true,
        ]);

        $systemContent = 'Contact support at support@dorr.example if you cannot resolve this - this is our own instruction, not user data.';

        $messages = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user', 'content' => 'My email is ali@example.com'],
        ];

        $result = $this->applyDataRules($provider, $messages);

        $this->assertSame($systemContent, $result[0]['content'], 'The system prompt is our own content, not user-supplied - it must pass through untouched.');
        $this->assertStringNotContainsString('ali@example.com', $result[1]['content']);
    }

    public function test_messages_pass_through_unchanged_when_no_data_rule_is_configured_for_the_provider(): void
    {
        $provider = $this->makeProvider();

        $messages = [
            ['role' => 'user', 'content' => 'My email is ali@example.com'],
        ];

        $result = $this->applyDataRules($provider, $messages);

        $this->assertSame('My email is ali@example.com', $result[0]['content']);
    }

    public function test_messages_pass_through_unchanged_when_the_data_rule_is_inactive(): void
    {
        $provider = $this->makeProvider();

        AiProviderDataRule::query()->create([
            'provider_id' => $provider->id,
            'sanitize_pii' => true,
            'sanitize_secrets' => true,
            'is_active' => false,
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'My email is ali@example.com'],
        ];

        $result = $this->applyDataRules($provider, $messages);

        $this->assertSame('My email is ali@example.com', $result[0]['content']);
    }
}
