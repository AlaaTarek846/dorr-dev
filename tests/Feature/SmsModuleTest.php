<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\SmsAccount;
use Modules\Sms\Models\SmsProvider;
use Modules\Sms\Services\Sms\SmsAvailabilityService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SMS module — provider registry, account credential handling and the
 * country-driven test-send flow.
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

        // Mirrors database/seeders/data/country-phone-rules.json.
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

        $permissions = [];

        foreach (['sms-accounts', 'sms-providers'] as $group) {
            foreach ($actions as $action) {
                $permissions[] = "{$group}.{$action}";
            }
        }

        return $permissions;
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
     * Credential maps per adapter. The ADAPTER schema is derived, but the
     * actual credential VALUES live ONLY on the account.
     *
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
        ][$key] ?? [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(string $key, string $name, array $overrides = []): SmsProvider
    {
        // Providers hold identity/status only — NO credentials.
        return SmsProvider::create(array_merge([
            'name' => $name,
            'key' => $key,
            'is_active' => true,
            'is_available' => true,
            'test_status' => 'passed',
            'last_tested_at' => now(),
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
    private function account(SmsProvider $provider, array $overrides = []): SmsAccount
    {
        $credentials = $this->credentials($provider->key);

        // The account is the single source of truth for credentials. Pass
        // PLAINTEXT: the model's `encrypted:array` cast handles encryption.
        return SmsAccount::create(array_merge([
            'provider_id' => $provider->id,
            'name' => 'Sales SMS',
            'sender' => $credentials['from'] ?? $credentials['sender'] ?? '+201001234567',
            'sender_type' => 'number',
            'configuration' => $credentials,
            'is_active' => true,
            'is_default' => true,
            'test_status' => 'passed',
            'last_tested_at' => now(),
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     | Authorization
     * ------------------------------------------------------------------ */

    public function test_api_requires_permission(): void
    {
        $this->admin([]);

        $this->getJson(self::BASE.'/sms-providers')->assertStatus(403);
        $this->postJson(self::BASE.'/sms-providers', [])->assertStatus(403);
        $this->getJson(self::BASE.'/sms-accounts')->assertStatus(403);
        $this->postJson(self::BASE.'/sms-accounts', [])->assertStatus(403);
    }

    public function test_registry_types_endpoint_exposes_every_adapter(): void
    {
        $this->admin($this->permissions());

        $response = $this->getJson(self::BASE.'/sms-providers/types');
        $response->assertOk();

        $types = collect($response->json('data'));
        $this->assertCount(2, $types, 'Both registered adapters must be exposed');

        $twilio = $types->firstWhere('value', 'twilio');
        $this->assertNotNull($twilio);
        $this->assertNotEmpty($twilio['fields'], 'Each adapter must expose its configuration schema');

        $misr = $types->firstWhere('value', 'sms_misr');
        $this->assertNotNull($misr);
        $this->assertNotEmpty($misr['fields'], 'Each adapter must expose its configuration schema');
    }

    /* ------------------------------------------------------------------ *
     | Providers
     * ------------------------------------------------------------------ */

    public function test_provider_holds_no_configuration(): void
    {
        $provider = $this->twilioProvider();

        $this->assertNull($provider->getRawOriginal('configuration'), 'Providers must not store credentials');
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
        ])->assertStatus(201);

        $provider = SmsProvider::where('key', 'sms_misr')->first();
        $this->assertNotNull($provider);
        $this->assertNull($provider->getRawOriginal('configuration'), 'No credentials stored on provider');

        $list = $this->getJson(self::BASE.'/sms-providers');
        $list->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('key', 'sms_misr'));
    }

    public function test_provider_delete_blocked_when_accounts_exist(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider();
        $this->account($provider);

        $this->deleteJson(self::BASE."/sms-providers/{$provider->id}")->assertStatus(400);
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

        $ready = $this->twilioProvider();
        $this->postJson(self::BASE."/sms-providers/{$ready->id}/test")->assertOk();

        $inactive = $this->provider('sms_misr', 'Misr Off', ['is_active' => false]);
        $this->postJson(self::BASE."/sms-providers/{$inactive->id}/test")->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Account credentials
     * ------------------------------------------------------------------ */

    public function test_account_configuration_is_encrypted_at_rest(): void
    {
        $account = $this->account($this->twilioProvider());

        $raw = $account->getRawOriginal('configuration');

        $this->assertStringNotContainsString('auth-token-secret-value', $raw, 'Secret must be encrypted at rest');
        $this->assertStringNotContainsString('account_sid', $raw, 'Configuration JSON must be encrypted');

        $this->assertSame('AC-test-sid-123456789', $account->configuration_plaintext['account_sid']);
        $this->assertSame('auth-token-secret-value', $account->configuration_plaintext['auth_token']);
    }

    public function test_account_credentials_never_exposed_in_api(): void
    {
        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider());

        $response = $this->getJson(self::BASE."/sms-accounts/{$account->id}");
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

    public function test_account_create_requires_credentials(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider();

        // auth_token is missing -> 422.
        $this->postJson(self::BASE.'/sms-accounts', [
            'provider_id' => $provider->id,
            'name' => 'Broken Account',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'from' => '+201001234567',
            ],
        ])->assertStatus(422);
    }

    public function test_account_store_persists_encrypted_configuration(): void
    {
        $this->admin($this->permissions());

        $this->postJson(self::BASE.'/sms-accounts', [
            'provider_id' => $this->twilioProvider()->id,
            'name' => 'Brand New',
            'is_default' => true,
            'configuration' => [
                'account_sid' => 'AC-test-sid-999999999',
                'auth_token' => 'auth-token-new-secret',
                'from' => '+201001234567',
            ],
        ])->assertStatus(201);

        $account = SmsAccount::where('name', 'Brand New')->firstOrFail();

        $this->assertStringNotContainsString(
            'auth-token-new-secret',
            $account->getRawOriginal('configuration'),
            'Stored account configuration must be encrypted',
        );
        $this->assertSame('AC-test-sid-999999999', $account->configuration_plaintext['account_sid']);
        $this->assertSame('auth-token-new-secret', $account->configuration_plaintext['auth_token']);
    }

    public function test_updating_credentials_keeps_blank_secrets_and_invalidates_the_test(): void
    {
        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider());

        // auth_token is left blank on purpose: the stored value must survive.
        $this->putJson(self::BASE."/sms-accounts/{$account->id}", [
            'name' => 'Renamed',
            'configuration' => [
                'account_sid' => 'AC-test-sid-123456789',
                'auth_token' => '',
                'from' => '+201009999999',
            ],
        ])->assertOk();

        $account->refresh();

        $this->assertSame('Renamed', $account->name);
        $this->assertSame('auth-token-secret-value', $account->configuration_plaintext['auth_token'], 'Blank secret must keep the stored value');
        $this->assertSame('+201009999999', $account->configuration_plaintext['from']);
        $this->assertSame('never_tested', $account->test_status, 'Editing credentials must invalidate the last test');
    }

    public function test_account_default_is_single(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider();
        $first = $this->account($provider, ['name' => 'A', 'is_default' => true]);
        $second = $this->account($provider, ['name' => 'B', 'is_default' => false]);

        $this->postJson(self::BASE."/sms-accounts/{$second->id}/set-default")->assertOk();

        $this->assertFalse((bool) $first->refresh()->is_default, 'Old default must be cleared');
        $this->assertTrue((bool) $second->refresh()->is_default, 'New default must be set');
    }

    public function test_account_toggle_active(): void
    {
        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider(), ['is_active' => true]);

        $this->patchJson(self::BASE."/sms-accounts/{$account->id}/status")->assertOk();

        $this->assertFalse((bool) $account->refresh()->is_active);
    }

    public function test_delete_multiple_accounts(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider();
        $keep = $this->account($provider, ['name' => 'Keep']);
        $drop = $this->account($provider, ['name' => 'Drop', 'is_default' => false]);

        $this->postJson(self::BASE.'/sms-accounts/delete-multiple', ['ids' => [$drop->id]])->assertOk();

        $this->assertDatabaseMissing('sms_accounts', ['id' => $drop->id]);
        $this->assertDatabaseHas('sms_accounts', ['id' => $keep->id]);
    }

    /* ------------------------------------------------------------------ *
     | Connection test
     * ------------------------------------------------------------------ */

    public function test_account_test_blocked_when_provider_inactive(): void
    {
        $this->admin($this->permissions());

        $provider = $this->twilioProvider(['is_active' => false]);
        $account = $this->account($provider);

        $this->postJson(self::BASE."/sms-accounts/{$account->id}/test")->assertStatus(422);

        $this->assertSame('passed', $account->refresh()->test_status, 'Test status must not change when the provider is inactive');
    }

    public function test_account_connection_test_success(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '10.0', 'currency' => 'USD'], 200),
        ]);

        $this->admin($this->permissions());

        $account = $this->account(
            $this->twilioProvider(),
            ['test_status' => 'never_tested', 'last_tested_at' => null],
        );

        $this->postJson(self::BASE."/sms-accounts/{$account->id}/test")->assertOk();

        $account->refresh();
        $this->assertSame('passed', $account->test_status);
        $this->assertNull($account->test_error);
    }

    public function test_account_draft_test_never_persists(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '42.5', 'currency' => 'USD'], 200),
        ]);

        $this->admin($this->permissions());

        $before = SmsAccount::count();

        $response = $this->postJson(self::BASE.'/sms-accounts/test-draft', [
            'provider_id' => $this->twilioProvider()->id,
            'configuration' => $this->credentials('twilio'),
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
        $this->assertSame($before, SmsAccount::count(), 'Draft test must not persist anything');
    }

    public function test_balance_endpoint_is_capability_gated(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['balance' => '42.5', 'currency' => 'USD'], 200),
        ]);

        $this->admin($this->permissions());

        // Twilio supports balance.
        $twilioAccount = $this->account($this->twilioProvider());
        $this->getJson(self::BASE."/sms-accounts/{$twilioAccount->id}/balance")->assertOk();

        // SMS Misr does not expose a balance API.
        $misrAccount = $this->account($this->smsMisrProvider(), ['is_default' => false]);
        $this->getJson(self::BASE."/sms-accounts/{$misrAccount->id}/balance")->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Send test SMS (country-driven)
     * ------------------------------------------------------------------ */

    public function test_send_test_sms_sends_a_country_normalized_number(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'num_segments' => '1'], 201),
        ]);

        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider(), ['test_status' => 'passed']);

        $response = $this->postJson(self::BASE.'/sms-accounts/send-test', [
            'account_id' => $account->id,
            'country_id' => $this->egypt->id,
            'to' => '01012345678',
            'message' => 'Hello from Dorr',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
        $this->assertSame('+201012345678', $response->json('data.to'), 'The national number must be normalized to E.164');

        // Twilio is called as a form-encoded request, so `To` arrives
        // percent-encoded (+ => %2B). Compare the parsed parameter rather than
        // the raw body, otherwise the assertion passes on a real failure.
        $twilioRequest = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), 'api.twilio.com'));

        $this->assertNotNull($twilioRequest, 'A request to api.twilio.com must have been sent');
        $this->assertSame('+201012345678', $twilioRequest['To'] ?? null, 'The national number must be sent to Twilio in E.164');
    }

    public function test_send_test_sms_requires_a_country(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider(), ['test_status' => 'passed']);

        $this->postJson(self::BASE.'/sms-accounts/send-test', [
            'account_id' => $account->id,
            'to' => '01012345678',
        ])->assertStatus(422);
    }

    public function test_send_test_sms_rejects_a_number_from_another_country(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $this->admin($this->permissions());

        $account = $this->account($this->twilioProvider(), ['test_status' => 'passed']);

        $response = $this->postJson(self::BASE.'/sms-accounts/send-test', [
            'account_id' => $account->id,
            'country_id' => $this->egypt->id,
            'to' => '0501234567', // Saudi number
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_send_test_sms_blocked_without_usable_account(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $this->admin($this->permissions());

        // Account never passed its test -> not usable.
        $untested = $this->account($this->twilioProvider(), ['test_status' => 'never_tested']);

        $this->postJson(self::BASE.'/sms-accounts/send-test', [
            'account_id' => $untested->id,
            'country_id' => $this->egypt->id,
            'to' => '01012345678',
        ])->assertStatus(422);

        // Provider inactive -> also not usable.
        $inactive = $this->account(
            $this->provider('sms_misr', 'Misr Inactive', ['is_active' => false]),
            ['name' => 'Acc 2', 'is_default' => false],
        );

        $this->postJson(self::BASE.'/sms-accounts/send-test', [
            'account_id' => $inactive->id,
            'country_id' => $this->egypt->id,
            'to' => '01012345678',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ *
     | Availability + dropdown
     * ------------------------------------------------------------------ */

    public function test_availability_gating(): void
    {
        $service = SmsAvailabilityService::instance();

        $this->assertFalse($service->isAvailable(), 'No accounts -> unavailable');

        $provider = $this->twilioProvider();
        $this->account($provider, ['is_active' => true, 'test_status' => 'passed']);
        $this->assertTrue($service->isAvailable(), 'Active provider + active tested account -> available');

        $provider->update(['is_active' => false]);
        $this->assertFalse($service->isAvailable(), 'Inactive provider -> unavailable');

        $provider->update(['is_active' => true]);
        SmsAccount::query()->update(['is_active' => false]);
        $this->assertFalse($service->isAvailable(), 'Inactive account -> unavailable');

        SmsAccount::query()->update(['is_active' => true, 'test_status' => 'failed']);
        $this->assertFalse($service->isAvailable(), 'Failed connection test -> unavailable');
    }

    public function test_dropdown_only_returns_active_accounts_of_active_providers(): void
    {
        $this->admin($this->permissions());

        $twilio = $this->twilioProvider();
        $good = $this->account($twilio, ['name' => 'Good Account']);

        // Provider inactive -> account excluded.
        $this->account(
            $this->provider('sms_misr', 'Misr Inactive', ['is_active' => false]),
            ['name' => 'Bad Account', 'is_default' => false],
        );

        // Account inactive on an active provider -> also excluded.
        $this->account(
            $twilio,
            ['name' => 'Inactive Acc', 'is_active' => false, 'is_default' => false],
        );

        $response = $this->getJson(self::BASE.'/sms-accounts/dropdown');
        $response->assertOk();

        $items = collect($response->json('data'));

        $this->assertTrue($items->contains('id', $good->id));
        $this->assertCount(1, $items, 'Only usable accounts are returned');
        $this->assertTrue($items->first()['supports_balance']);
    }
}
