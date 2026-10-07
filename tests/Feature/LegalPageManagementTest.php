<?php

namespace Tests\Feature;

use App\Models\Flag;
use App\Models\Language;
use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegalPageManagementTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $service;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);

        Language::create([
            'code' => 'en', 'direction' => 'ltr', 'is_default_website' => true,
            'is_default_dashboard' => true, 'stores_translation' => true,
            'status' => true, 'flag_id' => $flag->id,
        ]);

        Language::create([
            'code' => 'ar', 'direction' => 'rtl', 'is_default_website' => false,
            'is_default_dashboard' => false, 'stores_translation' => true,
            'status' => true, 'flag_id' => $flag->id,
        ]);

        $this->service = ServiceCategory::create([
            'module_name' => 'wallet_services', 'status' => true, 'sort_order' => 0,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function translations(string $en, string $ar = null): array
    {
        return [
            ['locale' => 'en', 'content' => $en],
            ['locale' => 'ar', 'content' => $ar ?? $en],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'privacy',
            'service_id' => null,
            'status' => true,
            'translations' => $this->translations('We never sell your personal data.'),
        ], $overrides);
    }

    private function actingAsAdmin(array $permissions = null): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active',
        ]);

        $permissions ??= [
            'legal-page.view', 'legal-page.create', 'legal-page.update',
            'legal-page.delete', 'legal-page.change-status', 'legal-page.multiple-delete',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    // ------------------------------------------------------------- type

    public function test_the_type_is_required_and_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload(['type' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->postJson('/api/admin/v1/legal-pages', $this->payload(['type' => 'jobs']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_the_default_type_is_privacy_when_omitted(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload();
        unset($payload['type']);

        $this->postJson('/api/admin/v1/legal-pages', $payload)
            ->assertCreated()
            ->assertJsonPath('data.type', 'privacy');
    }

    public function test_the_list_can_be_filtered_by_type(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'type' => 'privacy',
            'translations' => $this->translations('Privacy policy'),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'type' => 'term',
            'translations' => $this->translations('Terms'),
        ]))->assertCreated();

        $this->getJson('/api/admin/v1/legal-pages?type=term')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'term');

        $this->getJson('/api/admin/v1/legal-pages?type=privacy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'privacy');
    }

    // ------------------------------------------------------------- general vs service

    public function test_a_page_without_a_service_is_general(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.service', null);

        $this->assertDatabaseHas('legal_pages', ['type' => 'privacy', 'service_id' => null]);
    }

    public function test_a_page_can_be_linked_to_a_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.service_id', $this->service->id)
            ->assertJsonPath('data.service.id', $this->service->id);
    }

    public function test_a_page_can_be_moved_back_to_general(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/legal-pages/{$id}", $this->payload(['service_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.service_id', null);
    }

    public function test_a_page_cannot_point_at_a_service_that_does_not_exist(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload(['service_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    }

    public function test_a_service_can_only_have_one_page_of_a_type(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseCount('legal_pages', 1);
    }

    public function test_a_service_can_host_pages_of_different_types(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'type' => 'term',
            'service_id' => $this->service->id,
        ]))->assertCreated();

        $this->assertDatabaseCount('legal_pages', 2);
    }

    public function test_a_page_can_keep_its_own_service_when_updated(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/legal-pages/{$id}", $this->payload([
            'service_id' => $this->service->id,
            'translations' => $this->translations('Updated page content.'),
        ]))
            ->assertOk()
            ->assertJsonPath('data.service_id', $this->service->id);
    }

    public function test_a_page_cannot_move_to_a_service_that_already_has_one_of_the_type(): void
    {
        $this->actingAsAdmin();

        $other = ServiceCategory::create(['module_name' => null, 'status' => true, 'sort_order' => 1]);

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $other->id,
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/legal-pages/{$id}", $this->payload([
            'service_id' => $this->service->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    }

    public function test_a_service_freed_by_a_soft_deleted_page_can_be_reused(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/v1/legal-pages/{$id}")->assertNoContent();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated();
    }

    public function test_multiple_general_pages_without_a_service_are_allowed(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload())->assertCreated();
        $this->postJson('/api/admin/v1/legal-pages', $this->payload())->assertCreated();

        $this->assertDatabaseCount('legal_pages', 2);
    }

    // ------------------------------------------------------------------ translations

    public function test_it_stores_the_content_for_every_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'translations' => $this->translations(
                'We never sell your personal data.',
                'نحن لا نبيع بياناتك الشخصية أبداً.',
            ),
        ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.translations')
            ->assertJsonPath('data.content', 'We never sell your personal data.');

        $this->assertDatabaseHas('legal_page_translations', [
            'legal_page_id' => LegalPage::query()->firstOrFail()->id,
            'locale' => 'ar',
            'content' => 'نحن لا نبيع بياناتك الشخصية أبداً.',
        ]);
    }

    public function test_the_content_can_be_updated(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/admin/v1/legal-pages/{$id}", $this->payload([
            'translations' => $this->translations('Updated policy text.'),
        ]))
            ->assertOk()
            ->assertJsonPath('data.content', 'Updated policy text.');

        $this->assertDatabaseHas('legal_page_translations', [
            'legal_page_id' => $id, 'locale' => 'en', 'content' => 'Updated policy text.',
        ]);
    }

    public function test_the_content_is_required_for_every_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'translations' => [['locale' => 'en', 'content' => 'Only English']],
        ]))->assertStatus(422)->assertJsonValidationErrors('translations');

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'translations' => $this->translations('English', ''),
        ]))->assertStatus(422)->assertJsonValidationErrors('translations.1.content');
    }

    // ---------------------------------------------------------------------- ordering

    public function test_the_list_keeps_a_stable_id_ordering(): void
    {
        $this->actingAsAdmin();

        foreach (['First', 'Second', 'Third'] as $label) {
            $this->postJson('/api/admin/v1/legal-pages', $this->payload([
                'translations' => $this->translations("{$label} policy"),
            ]))->assertCreated();
        }

        $this->assertSame(
            ['First policy', 'Second policy', 'Third policy'],
            $this->getJson('/api/admin/v1/legal-pages')->assertOk()->json('data.*.content'),
        );
    }

    public function test_the_search_matches_the_page_content(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'translations' => $this->translations('Cookies are used for sessions.'),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'translations' => $this->translations('Location data is never collected.'),
        ]))->assertCreated();

        $search = json_encode([
            'searchKey' => 'Cookies',
            'columns' => [],
            'searchInTranslations' => true,
            'filterTranslationByLocale' => false,
        ]);

        $this->getJson('/api/admin/v1/legal-pages?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Cookies are used for sessions.');
    }

    // --------------------------------------------------------------------- lifecycle

    public function test_the_status_can_be_toggled(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/admin/v1/legal-pages/{$id}/status", ['status' => false])
            ->assertOk()
            ->assertJsonPath('data.status', false);
    }

    public function test_a_soft_deleted_page_keeps_its_content_and_can_be_restored(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/admin/v1/legal-pages/{$id}")->assertNoContent();

        $this->assertSoftDeleted('legal_pages', ['id' => $id]);
        $this->assertDatabaseCount('legal_page_translations', 2);

        $this->getJson("/api/admin/v1/legal-pages/{$id}")->assertNotFound();

        $this->postJson("/api/admin/v1/legal-pages/{$id}/restore")->assertOk();

        $this->getJson("/api/admin/v1/legal-pages/{$id}")->assertOk();
    }

    public function test_force_deleting_a_page_removes_its_content(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/legal-pages', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/admin/v1/legal-pages/{$id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/legal-pages/{$id}/force")->assertNoContent();

        $this->assertDatabaseMissing('legal_pages', ['id' => $id]);
        $this->assertDatabaseCount('legal_page_translations', 0);
    }

    public function test_several_pages_can_be_deleted_at_once(): void
    {
        $this->actingAsAdmin();

        $ids = collect([1, 2])->map(fn (int $index) => $this->postJson(
            '/api/admin/v1/legal-pages',
            $this->payload(['translations' => $this->translations("Policy {$index}")]),
        )->assertCreated()->json('data.id'))->all();

        $this->postJson('/api/admin/v1/legal-pages/delete-multiple', ['ids' => $ids])->assertOk();

        foreach ($ids as $id) {
            $this->assertSoftDeleted('legal_pages', ['id' => $id]);
        }
    }

    public function test_removing_the_service_category_a_page_pointed_at_makes_it_general(): void
    {
        $this->actingAsAdmin(['legal-page.view', 'legal-page.create', 'service_categories.delete']);

        $pageId = $this->postJson('/api/admin/v1/legal-pages', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}/force")->assertNoContent();

        $this->assertDatabaseHas('legal_pages', ['id' => $pageId, 'service_id' => null]);
    }

    // ------------------------------------------------------------------- permissions

    public function test_an_admin_without_the_legal_page_permissions_is_refused_everywhere(): void
    {
        $this->actingAsAdmin(['service_categories.view']);

        $this->getJson('/api/admin/v1/legal-pages')->assertForbidden();
        $this->postJson('/api/admin/v1/legal-pages', $this->payload())->assertForbidden();
        $this->postJson('/api/admin/v1/legal-pages/delete-multiple', ['ids' => [1]])->assertForbidden();
        $this->patchJson('/api/admin/v1/legal-pages/1/status', ['status' => true])->assertForbidden();
        $this->deleteJson('/api/admin/v1/legal-pages/1')->assertForbidden();
    }

    public function test_the_legal_page_permissions_are_seeded(): void
    {
        $this->seed(\Database\Seeders\Admin\AdminPermissionSeeder::class);

        foreach (['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'] as $action) {
            $this->assertDatabaseHas('permissions', [
                'name' => "legal-page.{$action}",
                'guard_name' => 'admin_api',
                'group_name' => 'legal-page',
            ]);
        }
    }
}