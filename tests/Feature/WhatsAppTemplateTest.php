<?php

namespace Tests\Feature;

use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Models\WhatsAppTemplate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'eg']);

        Language::create(['code' => 'ar', 'direction' => 'rtl', 'status' => true, 'flag_id' => $flag->id]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'status' => true, 'flag_id' => $flag->id]);
    }

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'WA Admin',
            'email' => 'wa-'.uniqid().'@example.com',
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

    private function whatsapp(array $overrides = []): WhatsApp
    {
        return WhatsApp::create(array_merge([
            'name' => 'Test WhatsApp',
            'access_token' => 'test-token',
            'phone_number_id' => 'test-phone-id',
            'business_account_id' => 'test-biz-id',
            'is_active' => true,
        ], $overrides));
    }

    /** A WhatsApp account that is ready to push templates to Meta. */
    private function readyWhatsapp(array $overrides = []): WhatsApp
    {
        return $this->whatsapp(array_merge([
            'test_status' => 'passed',
            'is_available' => true,
        ], $overrides));
    }

    private function templateData(array $overrides = []): array
    {
        return array_merge([
            'template_name' => 'otp_verification',
            'language_id' => Language::first()->id,
            'category' => 'AUTHENTICATION',
            'body' => 'Your verification code is {{1}}.',
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------------------ *
     | CRUD
     * ------------------------------------------------------------------ */

    public function test_create_template(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $response = $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData());

        $response->assertStatus(201);
        $this->assertDatabaseHas('whatsapp_templates', ['template_name' => 'otp_verification']);
    }

    public function test_list_templates(): void
    {
        $this->admin(['whatsapp.view']);
        $wa = $this->whatsapp();
        WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $response = $this->getJson(self::BASE.'/whatsapp/templates');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_template(): void
    {
        $this->admin(['whatsapp.view']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $response = $this->getJson(self::BASE."/whatsapp/templates/{$template->id}");

        $response->assertOk();
        $this->assertSame('otp_verification', $response->json('data.template_name'));
    }

    public function test_update_template(): void
    {
        $this->admin(['whatsapp.update']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $response = $this->putJson(self::BASE."/whatsapp/templates/{$template->id}", [
            'body' => 'Your new code is {{1}}.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_templates', ['body' => 'Your new code is {{1}}.']);
    }

    public function test_delete_template(): void
    {
        $this->admin(['whatsapp.delete']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->deleteJson(self::BASE."/whatsapp/templates/{$template->id}")->assertOk();

        $this->assertDatabaseMissing('whatsapp_templates', ['id' => $template->id]);
    }

    /* ------------------------------------------------------------------ *
     | Language
     * ------------------------------------------------------------------ */

    public function test_arabic_template_created(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $arabic = Language::where('code', 'ar')->first();

        $response = $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData([
            'language_id' => $arabic->id,
            'body' => 'رمز التحقق الخاص بك هو {{1}}',
        ]));

        $response->assertStatus(201);
    }

    public function test_same_template_different_languages_allowed(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $en = Language::where('code', 'en')->first();
        $ar = Language::where('code', 'ar')->first();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData(['language_id' => $en->id]))->assertStatus(201);
        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData(['language_id' => $ar->id]))->assertStatus(201);

        $this->assertSame(2, WhatsAppTemplate::count());
    }

    public function test_invalid_language_id_rejected(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData(['language_id' => 9999]))
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Template Validation
     * ------------------------------------------------------------------ */

    public function test_missing_otp_variable_rejected(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData([
            'body' => 'Your code is here.',
        ]))->assertStatus(422);
    }

    public function test_invalid_template_name_rejected(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData([
            'template_name' => 'Invalid Name!',
        ]))->assertStatus(422);
    }

    public function test_invalid_category_rejected(): void
    {
        $this->admin(['whatsapp.create']);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData([
            'category' => 'INVALID',
        ]))->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Meta Status
     * ------------------------------------------------------------------ */

    public function test_sync_updates_meta_status(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->admin(['whatsapp.test']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $response = $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/sync");

        $response->assertOk();
        $template->refresh();
        $this->assertNotNull($template->last_synced_at);
    }

    public function test_sync_failure_stores_error(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => 'Invalid token'], 401),
        ]);

        $this->admin(['whatsapp.test']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/sync")->assertOk();

        $template->refresh();
        $this->assertNotNull($template->last_sync_error);
    }

    public function test_sync_by_meta_template_id_uses_single_endpoint(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'meta-123',
                'status' => 'APPROVED',
            ], 200),
        ]);

        $this->admin(['whatsapp.test']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData([
            'whatsapp_id' => $wa->id,
            'meta_template_id' => 'meta-123',
            'is_active' => true,
        ]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/sync")->assertOk();

        $template->refresh();
        $this->assertSame('approved', $template->meta_status);
        $this->assertTrue($template->is_active);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/meta-123'));
    }

    public function test_sync_deactivates_template_when_meta_rejects_it(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'meta-123',
                'status' => 'REJECTED',
                'rejected_reason' => 'Invalid content',
            ], 200),
        ]);

        $this->admin(['whatsapp.test']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData([
            'whatsapp_id' => $wa->id,
            'meta_template_id' => 'meta-123',
            'is_active' => true,
        ]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/sync")->assertOk();

        $template->refresh();
        $this->assertSame('rejected', $template->meta_status);
        $this->assertFalse($template->is_active);
        $this->assertSame('Invalid content', $template->last_sync_error);
    }

    /* ------------------------------------------------------------------ *
     | Submit to Meta
     * ------------------------------------------------------------------ */

    public function test_submit_template_sends_upsert_payload_and_stores_meta_id(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => [['id' => 'meta-999']]], 200),
        ]);

        $this->admin(['whatsapp.update']);
        $wa = $this->readyWhatsapp();
        $template = WhatsAppTemplate::create($this->templateData([
            'whatsapp_id' => $wa->id,
            'language_id' => Language::where('code', 'en')->first()->id,
        ]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/submit")->assertOk();

        $template->refresh();
        $this->assertSame('meta-999', $template->meta_template_id);
        $this->assertSame('pending', $template->meta_status);
        $this->assertNull($template->last_sync_error);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/upsert_message_templates')) {
                return false;
            }

            $body = json_decode($request->body(), true);

            $this->assertSame(['en_US'], $body['languages']);
            $this->assertSame('AUTHENTICATION', $body['category']);
            $this->assertSame('otp_verification', $body['name']);
            // AUTHENTICATION templates always carry the mandatory OTP button.
            $this->assertSame(
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
                $body['components'][1]
            );
            $this->assertSame(['body_text' => ['123456']], $body['components'][0]['example']);

            return true;
        });
    }

    public function test_submit_fails_when_account_is_not_ready(): void
    {
        Http::fake();

        $this->admin(['whatsapp.update']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/submit")->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_submit_rejects_authentication_template_without_otp_variable(): void
    {
        Http::fake();

        $this->admin(['whatsapp.update']);
        $wa = $this->readyWhatsapp();
        $template = WhatsAppTemplate::create($this->templateData([
            'whatsapp_id' => $wa->id,
            'body' => 'Your code is here.',
        ]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/submit")->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_submit_failure_stores_meta_error(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'code' => 100,
                    'message' => 'Invalid parameter',
                    'error_data' => ['details' => 'Unsupported language'],
                ],
            ], 400),
        ]);

        $this->admin(['whatsapp.update']);
        $wa = $this->readyWhatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/submit")->assertStatus(422);

        $template->refresh();
        $this->assertStringContainsString('Invalid parameter', (string) $template->last_sync_error);
        $this->assertStringContainsString('Unsupported language', (string) $template->last_sync_error);
    }

    public function test_unauthorized_submit_returns_403(): void
    {
        Http::fake();

        $this->admin(['whatsapp.view']);
        $wa = $this->readyWhatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->postJson(self::BASE."/whatsapp/templates/{$template->id}/submit")->assertStatus(403);
    }

    /* ------------------------------------------------------------------ *
     | Import from Meta
     * ------------------------------------------------------------------ */

    public function test_import_creates_missing_template_and_never_duplicates(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => 'meta-777',
                    'name' => 'otp_verification',
                    'status' => 'APPROVED',
                    'language' => 'en_US',
                    'category' => 'AUTHENTICATION',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Your verification code is {{1}}.'],
                    ],
                ]],
            ], 200),
        ]);

        $this->admin(['whatsapp.test']);
        $wa = $this->readyWhatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates/import')->assertOk();
        $this->assertDatabaseHas('whatsapp_templates', [
            'whatsapp_id' => $wa->id,
            'meta_template_id' => 'meta-777',
            'meta_status' => 'approved',
            'body' => 'Your verification code is {{1}}.',
        ]);

        // A second import must update in place instead of creating a duplicate.
        $this->postJson(self::BASE.'/whatsapp/templates/import')->assertOk();
        $this->assertSame(1, WhatsAppTemplate::where('meta_template_id', 'meta-777')->count());
    }

    public function test_import_failure_returns_422(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token']], 401),
        ]);

        $this->admin(['whatsapp.test']);
        $this->readyWhatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates/import')->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     | Permissions
     * ------------------------------------------------------------------ */

    public function test_unauthorized_create_returns_403(): void
    {
        $this->admin([]);
        $this->whatsapp();

        $this->postJson(self::BASE.'/whatsapp/templates', $this->templateData())->assertStatus(403);
    }

    public function test_unauthorized_delete_returns_403(): void
    {
        $this->admin(['whatsapp.view']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $this->deleteJson(self::BASE."/whatsapp/templates/{$template->id}")->assertStatus(403);
    }

    /* ------------------------------------------------------------------ *
     | Resource — No Secrets
     * ------------------------------------------------------------------ */

    public function test_resource_never_exposes_secrets(): void
    {
        $this->admin(['whatsapp.view']);
        $wa = $this->whatsapp();
        $template = WhatsAppTemplate::create($this->templateData(['whatsapp_id' => $wa->id]));

        $response = $this->getJson(self::BASE."/whatsapp/templates/{$template->id}");

        $json = $response->json('data');
        $this->assertArrayNotHasKey('access_token', $json);
        $this->assertArrayNotHasKey('phone_number_id', $json);
        $this->assertArrayNotHasKey('business_account_id', $json);
    }
}
