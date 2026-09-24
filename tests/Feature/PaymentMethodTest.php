<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Database\Seeders\PaymentMethodSeeder;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Models\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        $flagSa = Flag::create(['code' => 'sa']);
        $flagEg = Flag::create(['code' => 'eg']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flagSa->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        $this->egypt = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => false, 'flag_id' => $flagEg->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        Language::create([
            'code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true,
            'stores_translation' => true, 'status' => true, 'flag_id' => $flagSa->id,
        ]);
    }

    private function method(string $code, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'code' => $code,
            'gateway' => 'myfatoorah',
            'type' => PaymentMethodType::Online,
            'is_global' => false,
            'supports_topup' => true,
            'status' => true,
            'credentials' => ['api_url' => 'https://mf.test', 'api_key' => 'SUPER-SECRET-KEY'],
        ], $overrides));
    }

    // ----------------------------------------------------------- availability

    public function test_a_method_linked_to_another_country_is_not_available_here(): void
    {
        $egyptOnly = $this->method('egypt_only');
        $egyptOnly->countryLinks()->create(['country_id' => $this->egypt->id]);

        $this->assertFalse(PaymentMethod::query()->availableForCountry($this->saudi)->where('code', 'egypt_only')->exists());
        $this->assertTrue(PaymentMethod::query()->availableForCountry($this->egypt)->where('code', 'egypt_only')->exists());
    }

    public function test_a_global_method_is_available_in_every_country(): void
    {
        $this->method('everywhere', ['is_global' => true]);

        $this->assertTrue(PaymentMethod::query()->availableForCountry($this->saudi)->where('code', 'everywhere')->exists());
        $this->assertTrue(PaymentMethod::query()->availableForCountry($this->egypt)->where('code', 'everywhere')->exists());
    }

    public function test_an_inactive_method_is_never_available_even_when_global(): void
    {
        $this->method('off', ['is_global' => true, 'status' => false]);

        $this->assertFalse(PaymentMethod::query()->availableForCountry($this->saudi)->where('code', 'off')->exists());
    }

    public function test_a_disabled_country_link_hides_the_method_for_that_country_only(): void
    {
        $method = $this->method('sa_paused');
        $method->countryLinks()->create(['country_id' => $this->saudi->id, 'status' => false]);
        $method->countryLinks()->create(['country_id' => $this->egypt->id, 'status' => true]);

        $this->assertFalse(PaymentMethod::query()->availableForCountry($this->saudi)->where('code', 'sa_paused')->exists());
        $this->assertTrue(PaymentMethod::query()->availableForCountry($this->egypt)->where('code', 'sa_paused')->exists());
    }

    public function test_the_provider_endpoint_lists_only_methods_for_the_resolved_country(): void
    {
        $sa = $this->method('sa_method');
        $sa->countryLinks()->create(['country_id' => $this->saudi->id]);
        $eg = $this->method('eg_method');
        $eg->countryLinks()->create(['country_id' => $this->egypt->id]);
        $this->method('global_method', ['is_global' => true]);

        $provider = Provider::create(['name' => 'P', 'email' => 'p@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($provider, [], 'provider_api');

        $codes = collect($this->getJson('/api/provider/v1/wallet/payment-methods', ['X-Country' => 'SA'])
            ->assertOk()
            ->json('data'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['sa_method', 'global_method'], $codes);
    }

    // ------------------------------------------------------------ credentials

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $method = $this->method('secure');

        $raw = DB::table('payment_methods')->where('id', $method->id)->value('credentials');

        $this->assertStringNotContainsString('SUPER-SECRET-KEY', (string) $raw);
        $this->assertSame('SUPER-SECRET-KEY', $method->fresh()->credentials['api_key']); // still readable through the model
    }

    public function test_credentials_never_appear_in_any_api_response(): void
    {
        $method = $this->method('leak_check', ['is_global' => true]);
        $method->translations()->create(['locale' => 'en', 'name' => 'Leak Check']);

        $provider = Provider::create(['name' => 'P', 'email' => 'p2@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($provider, [], 'provider_api');

        $body = $this->getJson('/api/provider/v1/wallet/payment-methods', ['X-Country' => 'SA'])->assertOk()->getContent();

        $this->assertStringNotContainsString('SUPER-SECRET-KEY', $body);
        $this->assertStringNotContainsString('credentials', $body);
    }

    // ------------------------------------------------------------------ admin

    private function adminWith(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_admin_can_create_a_method_and_the_response_has_no_credentials(): void
    {
        $this->adminWith(['payment-methods.create']);

        $response = $this->postJson('/api/admin/v1/payment-methods', [
            'code' => 'new_gateway',
            'gateway' => 'myfatoorah',
            'type' => 'online',
            'status' => true,
            'credentials' => ['api_url' => 'https://mf.test', 'api_key' => 'SUPER-SECRET-KEY'],
            'translations' => [['locale' => 'en', 'name' => 'New Gateway', 'description' => 'Pay here']],
        ]);

        $response->assertCreated();
        $this->assertStringNotContainsString('SUPER-SECRET-KEY', $response->getContent());

        $stored = PaymentMethod::query()->where('code', 'new_gateway')->firstOrFail();
        $this->assertSame('SUPER-SECRET-KEY', $stored->credentials['api_key']);
        $this->assertSame('Pay here', $stored->translations()->where('locale', 'en')->value('description')); // description persisted, not dropped
        $this->assertNotNull($stored->created_by);
    }

    public function test_an_online_method_without_credentials_is_rejected(): void
    {
        $this->adminWith(['payment-methods.create']);

        $this->postJson('/api/admin/v1/payment-methods', [
            'code' => 'no_creds',
            'gateway' => 'myfatoorah',
            'type' => 'online',
            'translations' => [['locale' => 'en', 'name' => 'No Creds']],
        ])->assertStatus(422);
    }

    public function test_editing_a_method_without_resending_credentials_keeps_the_stored_ones(): void
    {
        $this->adminWith(['payment-methods.update']);
        $method = $this->method('keep_secret');

        $this->putJson("/api/admin/v1/payment-methods/{$method->id}", [
            'code' => 'keep_secret', 'gateway' => 'myfatoorah', 'type' => 'online',
            'translations' => [['locale' => 'en', 'name' => 'Renamed']],
        ])->assertOk();

        $this->assertSame('SUPER-SECRET-KEY', $method->fresh()->credentials['api_key']);

        $this->putJson("/api/admin/v1/payment-methods/{$method->id}", [
            'code' => 'keep_secret', 'gateway' => 'myfatoorah', 'type' => 'online',
            'credentials' => ['api_url' => 'https://new.test', 'api_key' => 'NEW-KEY'],
            'translations' => [['locale' => 'en', 'name' => 'Renamed']],
        ])->assertOk();

        $this->assertSame('NEW-KEY', $method->fresh()->credentials['api_key']);
    }

    public function test_the_sandbox_gateway_needs_no_credentials(): void
    {
        $this->adminWith(['payment-methods.create']);

        $this->postJson('/api/admin/v1/payment-methods', [
            'code' => 'fake_bank', 'gateway' => 'sandbox', 'type' => 'online',
            'translations' => [['locale' => 'en', 'name' => 'Fake bank']],
        ])->assertCreated();
    }

    public function test_an_admin_without_the_permission_cannot_create_a_method(): void
    {
        $this->adminWith(['payment-methods.view']);

        $this->postJson('/api/admin/v1/payment-methods', [
            'code' => 'x', 'gateway' => 'manual', 'type' => 'manual',
            'translations' => [['locale' => 'en', 'name' => 'X']],
        ])->assertForbidden();
    }

    public function test_admin_can_link_a_method_to_countries_with_limits(): void
    {
        $this->adminWith(['payment-methods.update']);
        $method = $this->method('to_link');

        $this->putJson("/api/admin/v1/payment-methods/{$method->id}/countries", [
            'countries' => [
                ['country_id' => $this->saudi->id, 'min_amount_minor' => 1000, 'max_amount_minor' => 500000],
                ['country_id' => $this->egypt->id],
            ],
        ])->assertOk();

        $this->assertSame(2, $method->countryLinks()->count());
        $this->assertSame(1000, $method->countryLinks()->where('country_id', $this->saudi->id)->value('min_amount_minor'));

        // A second sync replaces the whole set.
        $this->putJson("/api/admin/v1/payment-methods/{$method->id}/countries", [
            'countries' => [['country_id' => $this->egypt->id]],
        ])->assertOk();

        $this->assertSame([$this->egypt->id], $method->countryLinks()->pluck('country_id')->all());
    }

    // ----------------------------------------------------------------- seeder

    public function test_seeder_lists_all_gateways_but_only_the_ones_with_credentials_are_configured(): void
    {
        config(['wallet.sandbox_enabled' => false, 'wallet.gateway_seed_credentials' => [
            'myfatoorah' => ['api_url' => 'https://mf.test', 'api_key' => 'k'],
            'arb' => ['tranportal_id' => null, 'tranportal_password' => null, 'tranportal_resource_key' => null, 'hosted_url' => null],
            'urpay' => ['mode' => 'test', 'payment_url' => null],
        ]]);

        $this->seed(PaymentMethodSeeder::class);

        // All three are listed (visible in the app), linked to Saudi only…
        $this->assertSame(3, PaymentMethod::query()->availableForCountry($this->saudi)->count());
        $this->assertSame(0, PaymentMethod::query()->availableForCountry($this->egypt)->count());

        // …but only the one with credentials can be charged; the others are "coming soon".
        $configured = PaymentMethod::query()->get()->filter->isConfigured()->pluck('code')->all();
        $this->assertSame(['myfatoorah_card'], array_values($configured));
    }

    public function test_reseeding_never_undoes_what_the_admin_set_in_the_dashboard(): void
    {
        config(['wallet.sandbox_enabled' => false, 'wallet.gateway_seed_credentials' => [
            'myfatoorah' => ['api_url' => null, 'api_key' => null],
            'arb' => ['tranportal_id' => null, 'tranportal_password' => null, 'tranportal_resource_key' => null, 'hosted_url' => null],
            'urpay' => ['mode' => 'test', 'payment_url' => null],
        ]]);

        $this->seed(PaymentMethodSeeder::class);
        $arb = PaymentMethod::query()->where('code', 'arb_card')->firstOrFail();
        $arb->update(['status' => false, 'credentials' => ['tranportal_id' => 'a', 'tranportal_password' => 'b', 'tranportal_resource_key' => 'c', 'hosted_url' => 'https://x.test']]);

        $this->seed(PaymentMethodSeeder::class);

        $arb->refresh();
        $this->assertFalse($arb->status, 'the admin turned it off');
        $this->assertTrue($arb->isConfigured(), 'credentials entered in the dashboard survive a re-seed with an empty .env');
    }

    public function test_the_mobile_list_flags_unconfigured_gateways_as_coming_soon(): void
    {
        config(['wallet.sandbox_enabled' => false, 'wallet.gateway_seed_credentials' => [
            'myfatoorah' => ['api_url' => 'https://mf.test', 'api_key' => 'k'],
            'arb' => ['tranportal_id' => null, 'tranportal_password' => null, 'tranportal_resource_key' => null, 'hosted_url' => null],
            'urpay' => ['mode' => 'test', 'payment_url' => null],
        ]]);
        $this->seed(PaymentMethodSeeder::class);

        $provider = Provider::create(['name' => 'P', 'email' => 'p2@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($provider, [], 'provider_api');

        $response = $this->getJson('/api/provider/v1/wallet/payment-methods', ['X-Country' => 'SA'])->assertOk();

        $flags = collect($response->json('data'))->pluck('coming_soon', 'code')->all();
        $this->assertSame(['myfatoorah_card' => false, 'arb_card' => true, 'urpay_wallet' => true], $flags);
    }
}
