<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\WhatsApp;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/v1';

    private Country $country;

    private Country $otherCountry;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'eg']);
        $otherFlag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'E£', 'status' => true]);
        $otherCurrency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'status' => true]);

        $this->country = Country::create([
            'code' => 'eg',
            'name' => 'Egypt',
            'dial_code' => '+20',
            'phone_length' => 10,
            'phone_starts_with' => '1',
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        $this->otherCountry = Country::create([
            'code' => 'sa',
            'name' => 'Saudi Arabia',
            'dial_code' => '+966',
            'phone_length' => 9,
            'phone_starts_with' => '5',
            'flag_id' => $otherFlag->id,
            'currency_id' => $otherCurrency->id,
            'status' => true,
        ]);
    }

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'WA Config Admin',
            'email' => 'wa-config-'.uniqid().'@example.com',
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main WhatsApp',
            'access_token' => 'token',
            'phone_number_id' => 'phone-id',
            'phone_number' => '+201001234567',
            'phone_country_id' => $this->country->id,
            'business_account_id' => 'biz-id',
        ], $overrides);
    }

    /* ------------------------------------------------------------------ *
     | Phone number
     * ------------------------------------------------------------------ */

    public function test_stores_phone_number_and_country(): void
    {
        $this->admin(['whatsapp.create']);

        $response = $this->postJson(self::BASE.'/whatsapp', $this->payload());

        $response->assertOk();
        $this->assertDatabaseHas('whatsapps', [
            'phone_number' => '+201001234567',
            'phone_country_id' => $this->country->id,
        ]);

        $this->assertSame('+201001234567', $response->json('data.phone_number'));
        $this->assertSame($this->country->id, $response->json('data.phone_country_id'));
        $this->assertSame('eg', $response->json('data.phone_country.code'));
    }

    public function test_invalid_phone_number_rejected(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload(['phone_number' => 'not-a-number']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_unknown_phone_country_rejected(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload(['phone_country_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_country_id');
    }

    public function test_invalid_api_version_rejected(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload(['api_version' => 'latest']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_version');
    }

    /* ------------------------------------------------------------------ *
     | Single instance
     * ------------------------------------------------------------------ */

    public function test_store_updates_the_single_instance_instead_of_creating_a_second(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload())->assertOk();
        $this->postJson(self::BASE.'/whatsapp', $this->payload(['name' => 'Renamed']))->assertOk();

        $this->assertSame(1, WhatsApp::count());
        $this->assertDatabaseHas('whatsapps', ['name' => 'Renamed']);
    }

    public function test_store_keeps_existing_secrets_when_left_blank(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload())->assertOk();
        $this->postJson(self::BASE.'/whatsapp', [
            'name' => 'Renamed',
            'phone_number' => '+201001234568',
        ])->assertOk();

        $whatsapp = WhatsApp::first();
        $this->assertSame('token', $whatsapp->access_token);
        $this->assertSame('phone-id', $whatsapp->phone_number_id);
        $this->assertSame('+201001234568', $whatsapp->phone_number);
    }

    /* ------------------------------------------------------------------ *
     | Supported countries
     * ------------------------------------------------------------------ */

    public function test_supported_countries_are_persisted_and_exposed(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload(['countries' => [$this->country->id]]))
            ->assertOk()
            ->assertJsonPath('data.countries', [$this->country->id]);

        $this->assertDatabaseHas('whatsapp_countries', [
            'country_id' => $this->country->id,
            'is_active' => true,
        ]);

        // Sending an empty list clears the relation.
        $this->postJson(self::BASE.'/whatsapp', $this->payload(['countries' => []]))->assertOk();

        $this->assertDatabaseMissing('whatsapp_countries', ['country_id' => $this->country->id]);
    }

    public function test_invalid_country_id_in_list_rejected(): void
    {
        $this->admin(['whatsapp.create']);

        $this->postJson(self::BASE.'/whatsapp', $this->payload(['countries' => [9999]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('countries.0');
    }

    public function test_several_supported_countries_are_persisted_and_exposed(): void
    {
        $this->admin(['whatsapp.create']);

        $ids = [$this->country->id, $this->otherCountry->id];

        $response = $this->postJson(self::BASE.'/whatsapp', $this->payload(['countries' => $ids]))
            ->assertOk();

        $returned = collect($response->json('data.countries'))->map(
            fn ($item) => is_array($item) ? ($item['id'] ?? null) : $item
        )->all();

        sort($returned);
        $expected = $ids;
        sort($expected);

        $this->assertSame($expected, $returned);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('whatsapp_countries', [
                'country_id' => $id,
                'is_active' => true,
            ]);
        }

        $this->assertSame(2, WhatsApp::first()->countries()->count());
    }

    /* ------------------------------------------------------------------ *
     | Connection test
     * ------------------------------------------------------------------ */

    public function test_successful_connection_marks_the_account_available(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'phone-id',
                'name' => 'Main',
                'display_phone_number' => '+20 100 123 4567',
            ], 200),
        ]);

        $this->admin(['whatsapp.test']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/test-connection')->assertOk();

        $whatsapp = WhatsApp::first();
        $this->assertSame('passed', $whatsapp->test_status);
        $this->assertTrue($whatsapp->is_available);
        $this->assertNotNull($whatsapp->last_tested_at);
        $this->assertNull($whatsapp->test_error);
    }

    public function test_failed_connection_marks_the_account_unavailable(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 190, 'message' => 'Invalid OAuth token'],
            ], 401),
        ]);

        $this->admin(['whatsapp.test']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/test-connection')->assertOk();

        $whatsapp = WhatsApp::first();
        $this->assertSame('failed', $whatsapp->test_status);
        $this->assertFalse($whatsapp->is_available);
        $this->assertStringContainsString('Invalid OAuth token', (string) $whatsapp->test_error);
    }

    public function test_connection_request_never_leaks_the_token_in_the_query_string(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'graph-user'], 200)]);

        $this->admin(['whatsapp.test']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/test-connection')->assertOk();

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('secret-token', $request->url());
            $this->assertSame('Bearer secret-token', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    public function test_connection_uses_a_single_versioned_graph_path(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'graph-user'], 200)]);

        $this->admin(['whatsapp.test']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/test-connection')->assertOk();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/v25.0/v25.0/'));
    }

    /* ------------------------------------------------------------------ *
     | Resource — no secrets
     * ------------------------------------------------------------------ */

    public function test_resource_never_exposes_credentials(): void
    {
        $this->admin(['whatsapp.view']);
        $this->whatsapp();

        $json = $this->getJson(self::BASE.'/whatsapp')->assertOk()->json('data');

        // The access token is the only secret.
        $this->assertArrayNotHasKey('access_token', $json);
        $this->assertTrue($json['has_access_token']);

        // Meta identifiers are not secrets, so the admin can read and edit them.
        $this->assertSame('phone-id', $json['phone_number_id']);
        $this->assertSame('biz-id', $json['business_account_id']);
    }

    private function whatsapp(array $overrides = []): WhatsApp
    {
        return WhatsApp::create(array_merge([
            'name' => 'Test WhatsApp',
            'access_token' => 'secret-token',
            'phone_number_id' => 'phone-id',
            'business_account_id' => 'biz-id',
            'is_active' => true,
        ], $overrides));
    }
}
