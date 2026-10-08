<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\SmsProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SMS provider credential blob: optional default configuration on the provider
 * row (encrypted at rest, never exposed) plus the draft connection test used by
 * the provider modal's Test button before saving.
 */
class SmsProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/v1';

    private function admin(array $permissions = []): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'Sms Admin',
            'email' => 'sms-'.uniqid().'@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        foreach (['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete', 'test'] as $action) {
            Permission::findOrCreate("sms-providers.$action", 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function twilioProvider(array $overrides = []): SmsProvider
    {
        return SmsProvider::create(array_merge([
            'name' => 'Primary Twilio',
            'key' => 'twilio',
            'is_active' => true,
            'is_available' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function twilioCredentials(array $overrides = []): array
    {
        return array_merge([
            'account_sid' => 'AC-test-sid-123456789',
            'auth_token' => 'auth-token-secret-value',
            'from' => '+201001234567',
        ], $overrides);
    }

    public function test_provider_store_persists_configuration_encrypted(): void
    {
        $this->admin(['sms-providers.create', 'sms-providers.view']);

        $response = $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Twilio Main',
            'key' => 'twilio',
            'configuration' => $this->twilioCredentials(),
        ]);

        $response->assertStatus(201);

        $provider = SmsProvider::where('key', 'twilio')->firstOrFail();
        $raw = $provider->getRawOriginal('configuration');

        $this->assertNotSame('', (string) $raw, 'Provider credentials must be stored');
        $this->assertStringNotContainsString('auth-token-secret-value', $raw, 'Secret must be encrypted at rest');
        $this->assertStringNotContainsString('account_sid', $raw, 'Configuration JSON must be encrypted');
        $this->assertSame('AC-test-sid-123456789', $provider->configuration_plaintext['account_sid']);
        $this->assertSame('auth-token-secret-value', $provider->configuration_plaintext['auth_token']);
    }

    public function test_provider_store_rejects_incomplete_configuration(): void
    {
        $this->admin(['sms-providers.create']);

        $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Broken Twilio',
            'key' => 'twilio',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'from' => '+201001234567',
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('sms_providers', ['name' => 'Broken Twilio']);
    }

    public function test_provider_update_preserves_blank_secrets(): void
    {
        $this->admin(['sms-providers.update']);

        $provider = $this->twilioProvider(['configuration' => $this->twilioCredentials()]);

        $this->putJson(self::BASE."/sms-providers/{$provider->id}", [
            'name' => 'Renamed Twilio',
            'key' => 'twilio',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'auth_token' => '',
                'from' => '+201009999999',
            ],
        ])->assertOk();

        $provider->refresh();

        $this->assertSame('Renamed Twilio', $provider->name);
        $this->assertSame('auth-token-secret-value', $provider->configuration_plaintext['auth_token'], 'Blank secret must keep the stored value');
        $this->assertSame('+201009999999', $provider->configuration_plaintext['from']);
    }

    public function test_provider_configuration_never_exposed_in_api(): void
    {
        $this->admin(['sms-providers.view']);

        $provider = $this->twilioProvider(['configuration' => $this->twilioCredentials()]);

        $response = $this->getJson(self::BASE."/sms-providers/{$provider->id}");
        $response->assertOk();

        $json = $response->json('data');
        $this->assertArrayNotHasKey('configuration', $json, 'Raw configuration must never be exposed');

        $fields = $json['configuration_meta']['fields'] ?? [];
        $authToken = collect($fields)->firstWhere('key', 'auth_token');

        $this->assertNotNull($authToken, 'auth_token field should exist in schema');
        $this->assertTrue($authToken['secret']);
        $this->assertNull($authToken['value'], 'Secret value must not be returned');
        $this->assertTrue($authToken['is_set'], 'Secret should be reported as set, not leaked');

        $from = collect($fields)->firstWhere('key', 'from');
        $this->assertFalse($from['secret']);
        $this->assertSame('+201001234567', $from['value'], 'Non-secret values are exposed');
    }

    public function test_provider_test_draft_success(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '10.0', 'currency' => 'USD'], 200),
        ]);

        $this->admin(['sms-providers.test']);

        $before = SmsProvider::count();

        $response = $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => $this->twilioCredentials(),
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
        $this->assertSame($before, SmsProvider::count(), 'Draft test must not persist anything');
    }

    public function test_provider_test_draft_merges_stored_secrets_on_edit(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '20.0', 'currency' => 'USD'], 200),
        ]);

        $this->admin(['sms-providers.test']);

        $provider = $this->twilioProvider(['configuration' => $this->twilioCredentials()]);

        // auth_token is blank: the stored secret must be merged in so the test
        // still has the full credential set.
        $response = $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'provider_id' => $provider->id,
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'auth_token' => '',
                'from' => '+201009999999',
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
    }

    public function test_provider_test_draft_rejects_incomplete_configuration(): void
    {
        $this->admin(['sms-providers.test']);

        $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'from' => '+201001234567',
            ],
        ])->assertStatus(422);
    }

    public function test_provider_test_draft_requires_permission(): void
    {
        $this->admin([]);

        $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => $this->twilioCredentials(),
        ])->assertStatus(403);
    }
}
