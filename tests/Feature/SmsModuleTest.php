<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\SmsProvider;
use Modules\Sms\Services\Sms\SmsAvailabilityService;
use Modules\Sms\Services\Sms\SmsProviderService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SMS module — provider registry, provider-level configuration,
 * country mapping, priority, and the OTP-ready readiness checks.
 */
class SmsModuleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/v1';

    private Country $egypt;

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'E£']);
        $flag = Flag::create(['code' => 'eg']);

        $this->egypt = Country::create([
            'code' => 'EG',
            'dial_code' => '+20',
            'phone_starts_with' => '1',
            'phone_length' => 10,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        $this->saudi = Country::create([
            'code' => 'SA',
            'dial_code' => '+966',
            'phone_starts_with' => '5',
            'phone_length' => 9,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return list<string>
     */
    private function permissions(): array
    {
        $actions = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete', 'test'];

        return array_map(
            fn (string $action) => "sms-providers.{$action}",
            $actions,
        );
    }

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'Sms Admin',
            'email' => 'sms-'.uniqid().'@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(string $key): array
    {
        return [
            'twilio' => [
                'account_sid' => 'AC-test-sid-123456789',
                'auth_token' => 'auth-token-secret-value',
                'from' => '+201001234567',
            ],
            'sms_misr' => [
                'username' => 'misr-user',
                'password' => 'misr-pass-secret',
                'sender' => 'DORR',
            ],
            'four_jawaly' => [
                'api_key' => 'jawaly-api-key',
                'api_secret' => 'jawaly-api-secret',
                'sender' => 'DORR',
            ],
        ][$key] ?? [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(string $key, string $name, array $overrides = []): SmsProvider
    {
        return SmsProvider::create(array_merge([
            'name' => $name,
            'key' => $key,
            'is_active' => true,
            'is_available' => true,
        ], $overrides));
    }

    /**
     * Provider with stored configuration and a passed test — ready
     * for OTP sending.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configuredProvider(string $key, string $name, array $overrides = []): SmsProvider
    {
        return SmsProvider::create(array_merge([
            'name' => $name,
            'key' => $key,
            'is_active' => true,
            'is_available' => true,
            'test_status' => 'passed',
            'last_tested_at' => now(),
            'configuration' => $this->credentials($key),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function twilioProvider(array $overrides = []): SmsProvider
    {
        return $this->provider('twilio', 'Primary Twilio', $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function smsMisrProvider(array $overrides = []): SmsProvider
    {
        return $this->provider('sms_misr', 'Misr Primary', $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function twilioConfiguredProvider(array $overrides = []): SmsProvider
    {
        return $this->configuredProvider('twilio', 'Primary Twilio', $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function smsMisrConfiguredProvider(array $overrides = []): SmsProvider
    {
        return $this->configuredProvider('sms_misr', 'Misr Primary', $overrides);
    }

    /* ------------------------------------------------------------------ *
     | Authorization
     * ------------------------------------------------------------------ */

    public function test_api_requires_permission(): void
    {
        $this->admin([]);

        $this->getJson(self::BASE.'/sms-providers')->assertStatus(403);
        $this->postJson(self::BASE.'/sms-providers', [])->assertStatus(403);
    }

    public function test_registry_types_endpoint_exposes_every_adapter(): void
    {
        $this->admin($this->permissions());

        $response = $this->getJson(self::BASE.'/sms-providers/types');
        $response->assertOk();

        $types = collect($response->json('data'));
        $this->assertCount(3, $types, 'Every registered adapter must be exposed');

        $twilio = $types->firstWhere('value', 'twilio');
        $this->assertNotNull($twilio);
        $this->assertNotEmpty($twilio['fields'], 'Each adapter must expose its configuration schema');

        $misr = $types->firstWhere('value', 'sms_misr');
        $this->assertNotNull($misr);
        $this->assertNotEmpty($misr['fields'], 'Each adapter must expose its configuration schema');

        $jawaly = $types->firstWhere('value', 'four_jawaly');
        $this->assertNotNull($jawaly);
        $this->assertNotEmpty($jawaly['fields'], 'Each adapter must expose its configuration schema');
    }

    /* ------------------------------------------------------------------ *
     | Providers
     * ------------------------------------------------------------------ */

    public function test_provider_can_hold_configuration(): void
    {
        $provider = $this->twilioConfiguredProvider();

        $this->assertNotNull($provider->getRawOriginal('configuration'), 'Provider must store credentials');
        $this->assertSame('AC-test-sid-123456789', $provider->configuration_plaintext['account_sid']);
    }

    public function test_provider_create_rejects_unknown_key_but_requires_no_config(): void
    {
        $this->admin($this->permissions());

        $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Unknown',
            'key' => 'not_a_real_provider',
        ])->assertStatus(422);

        // Providers are identity-only: a valid key succeeds without config.
        $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Plain Twilio',
            'key' => 'twilio',
        ])->assertStatus(201);
    }

    public function test_provider_store_and_index_work(): void
    {
        $this->admin($this->permissions());

        $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Misr Main',
            'key' => 'sms_misr',
            'is_active' => true,
            'priority' => 2,
        ])->assertStatus(201);

        $provider = SmsProvider::where('key', 'sms_misr')->first();
        $this->assertNotNull($provider);
        $this->assertSame(2, $provider->priority);
        $this->assertNull($provider->getRawOriginal('configuration'), 'No credentials stored on provider');

        $list = $this->getJson(self::BASE.'/sms-providers');
        $list->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('key', 'sms_misr'));
    }

    public function test_provider_delete_works_without_accounts(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider();

        $this->deleteJson(self::BASE."/sms-providers/{$provider->id}")->assertOk();
    }

    public function test_provider_priority_defaults_to_one(): void
    {
        $this->admin($this->permissions());

        $this->postJson(self::BASE.'/sms-providers', [
            'name' => 'Twilio Priority',
            'key' => 'twilio',
        ])->assertStatus(201);

        $provider = SmsProvider::where('key', 'twilio')->first();
        $this->assertSame(1, $provider->priority);
    }

    public function test_provider_toggle_active(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider(['is_active' => true]);

        $this->patchJson(self::BASE."/sms-providers/{$provider->id}/status")->assertOk();

        $this->assertFalse((bool) $provider->refresh()->is_active);
    }

    public function test_provider_readiness_test(): void
    {
        $this->admin($this->permissions());

        // A configured provider with a passed test is ready.
        $ready = $this->twilioConfiguredProvider();
        $this->postJson(self::BASE."/sms-providers/{$ready->id}/test")->assertOk();

        // An inactive provider is not ready.
        $inactive = $this->provider('sms_misr', 'Misr Off', ['is_active' => false]);
        $this->postJson(self::BASE."/sms-providers/{$inactive->id}/test")->assertStatus(422);

        // A provider without a passed test is not ready.
        $ready->update(['test_status' => 'never_tested']);
        $this->postJson(self::BASE."/sms-providers/{$ready->id}/test")->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Configuration encryption (provider-level now)
     * ------------------------------------------------------------------ */

    public function test_provider_configuration_is_encrypted_at_rest(): void
    {
        $provider = $this->twilioConfiguredProvider();

        $raw = $provider->getRawOriginal('configuration');

        $this->assertStringNotContainsString('auth-token-secret-value', $raw, 'Secret must be encrypted at rest');
        $this->assertStringNotContainsString('account_sid', $raw, 'Configuration JSON must be encrypted');

        $this->assertSame('AC-test-sid-123456789', $provider->configuration_plaintext['account_sid']);
        $this->assertSame('auth-token-secret-value', $provider->configuration_plaintext['auth_token']);
    }

    public function test_provider_configuration_never_exposed_in_api(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioConfiguredProvider();

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

    public function test_provider_update_preserves_blank_secrets(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioConfiguredProvider();

        $this->putJson(self::BASE."/sms-providers/{$provider->id}", [
            'name' => 'Renamed',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'auth_token' => '',
                'from' => '+201009999999',
            ],
        ])->assertOk();

        $provider->refresh();

        $this->assertSame('Renamed', $provider->name);
        $this->assertSame('auth-token-secret-value', $provider->configuration_plaintext['auth_token'], 'Blank secret must keep the stored value');
        $this->assertSame('+201009999999', $provider->configuration_plaintext['from']);
    }

    /* ------------------------------------------------------------------ *
     | Test-draft
     * ------------------------------------------------------------------ */

    public function test_provider_test_draft_success(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '42.5', 'currency' => 'USD'], 200),
        ]);

        $this->admin($this->permissions());

        $provider = $this->twilioProvider();

        $response = $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => $this->credentials('twilio'),
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
    }

    public function test_provider_test_draft_merges_stored_secrets_on_edit(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '42.5', 'currency' => 'USD'], 200),
        ]);

        $this->admin($this->permissions());

        $provider = $this->twilioConfiguredProvider();

        $response = $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => ['from' => '+201009999999'],
            'provider_id' => $provider->id,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
    }

    public function test_provider_test_draft_requires_permission(): void
    {
        $this->admin([]);

        $response = $this->postJson(self::BASE.'/sms-providers/test-draft', [
            'key' => 'twilio',
            'configuration' => $this->credentials('twilio'),
        ]);

        $response->assertStatus(403);
    }

    /* ------------------------------------------------------------------ *
     | Provider country mapping
     * ------------------------------------------------------------------ */

    public function test_provider_create_saves_multiple_countries(): void
    {
        $this->admin($this->permissions());

        $response = $this->postJson('/api/admin/v1/sms-providers', [
            'name' => 'Multi Twilio',
            'key' => 'twilio',
            'countries' => [$this->egypt->id, $this->saudi->id],
        ])->assertCreated();

        $response->assertJsonCount(2, 'data.countries');
        $this->assertEqualsCanonicalizing(
            [$this->egypt->id, $this->saudi->id],
            SmsProvider::findOrFail($response->json('data.id'))->countries->pluck('id')->all(),
        );
    }

    public function test_provider_update_replaces_countries_and_keeps_them_when_absent(): void
    {
        $this->admin($this->permissions());

        $provider = $this->provider('twilio', 'Twilio');
        $provider->countries()->attach([$this->egypt->id, $this->saudi->id]);

        $this->putJson('/api/admin/v1/sms-providers/'.$provider->id, [
            'name' => 'Twilio',
            'countries' => [$this->saudi->id],
        ])->assertOk()->assertJsonPath('data.countries', [$this->saudi->id]);

        // No `countries` key → mapping untouched.
        $this->putJson('/api/admin/v1/sms-providers/'.$provider->id, ['name' => 'Twilio 2'])
            ->assertOk()->assertJsonPath('data.countries', [$this->saudi->id]);

        // Empty array → mapping cleared.
        $this->putJson('/api/admin/v1/sms-providers/'.$provider->id, ['name' => 'Twilio 2', 'countries' => []])
            ->assertOk()->assertJsonPath('data.countries', []);
    }

    public function test_provider_countries_must_exist(): void
    {
        $this->admin($this->permissions());

        $this->postJson('/api/admin/v1/sms-providers', [
            'name' => 'Bad', 'key' => 'twilio', 'countries' => [999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('countries.0');
    }

    public function test_provider_country_mapping_query(): void
    {
        $this->admin($this->permissions());

        $twilio = $this->twilioConfiguredProvider(['priority' => 1]);
        $twilio->countries()->attach($this->egypt->id, ['is_active' => true]);
        $twilio->countries()->attach($this->saudi->id, ['is_active' => true]);

        $misr = $this->smsMisrConfiguredProvider(['priority' => 2]);
        $misr->countries()->attach($this->egypt->id, ['is_active' => true]);

        $available = app(SmsProviderService::class)
            ->activeProvidersForCountry($this->egypt);

        $keys = $available->pluck('key')->all();
        $this->assertContains('twilio', $keys);
        $this->assertContains('sms_misr', $keys);
        $this->assertSame('twilio', $keys[0], 'Twilio has lower priority');

        // Saudi only has Twilio.
        $saudiProviders = app(SmsProviderService::class)
            ->activeProvidersForCountry($this->saudi);
        $this->assertCount(1, $saudiProviders);
        $this->assertSame('twilio', $saudiProviders->first()->key);
    }

    public function test_provider_country_mapping_filters_by_active(): void
    {
        $twilio = $this->twilioConfiguredProvider();
        $twilio->countries()->attach($this->egypt->id, ['is_active' => true]);

        // Inactive country mapping should not count.
        $twilio->countries()->updateExistingPivot($this->egypt->id, ['is_active' => false]);

        $available = app(SmsProviderService::class)
            ->activeProvidersForCountry($this->egypt);
        $this->assertCount(0, $available);
    }

    /* ------------------------------------------------------------------ *
     | Availability (provider-based now)
     * ------------------------------------------------------------------ */

    public function test_availability_gating(): void
    {
        $service = SmsAvailabilityService::instance();

        $this->assertFalse($service->isAvailable(), 'No providers -> unavailable');

        $provider = $this->twilioConfiguredProvider();
        $this->assertTrue($service->isAvailable(), 'Active provider + passed test + config -> available');

        $provider->update(['is_active' => false]);
        $this->assertFalse($service->isAvailable(), 'Inactive provider -> unavailable');

        $provider->update(['is_active' => true, 'test_status' => 'never_tested']);
        $this->assertFalse($service->isAvailable(), 'Failed connection test -> unavailable');
    }

    public function test_dropdown_only_returns_active_providers(): void
    {
        $this->admin($this->permissions());

        $this->twilioConfiguredProvider();
        $this->provider('sms_misr', 'Misr Inactive', ['is_active' => false]);

        $response = $this->getJson(self::BASE.'/sms-providers/dropdown');
        $response->assertOk();

        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('twilio', $items->first()['key']);
    }
}
