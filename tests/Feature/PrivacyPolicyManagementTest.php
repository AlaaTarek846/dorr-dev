<?php

namespace Tests\Feature;

use App\Models\Flag;
use App\Models\Language;
use App\Models\PrivacyPolicy;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrivacyPolicyManagementTest extends TestCase
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
            'service_id' => null,
            'status' => true,
            'sort_order' => 0,
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
            'privacy-policy.view', 'privacy-policy.create', 'privacy-policy.update',
            'privacy-policy.delete', 'privacy-policy.change-status', 'privacy-policy.multiple-delete',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    // ------------------------------------------------------------- general vs service

    public function test_a_policy_without_a_service_is_general(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.service', null);

        $this->assertDatabaseHas('privacy_policies', ['service_id' => null, 'sort_order' => 0]);
    }

    public function test_a_policy_can_be_linked_to_a_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'service_id' => $this->service->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.service_id', $this->service->id)
            ->assertJsonPath('data.service.id', $this->service->id);
    }

    public function test_a_policy_can_be_moved_back_to_general(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/privacy-policies/{$id}", $this->payload(['service_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.service_id', null);
    }

    public function test_a_policy_cannot_point_at_a_service_that_does_not_exist(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload(['service_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    }

    // ------------------------------------------------------------------ translations

    public function test_it_stores_the_content_for_every_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => $this->translations(
                'We never sell your personal data.',
                'نحن لا نبيع بياناتك الشخصية أبداً.',
            ),
        ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.translations')
            ->assertJsonPath('data.content', 'We never sell your personal data.');

        $this->assertDatabaseHas('privacy_policy_translations', [
            'privacy_policy_id' => PrivacyPolicy::query()->firstOrFail()->id,
            'locale' => 'ar',
            'content' => 'نحن لا نبيع بياناتك الشخصية أبداً.',
        ]);
    }

    public function test_the_content_can_be_updated(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/privacy-policies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/admin/v1/privacy-policies/{$id}", $this->payload([
            'translations' => $this->translations('Updated policy text.'),
        ]))
            ->assertOk()
            ->assertJsonPath('data.content', 'Updated policy text.');

        $this->assertDatabaseHas('privacy_policy_translations', [
            'privacy_policy_id' => $id, 'locale' => 'en', 'content' => 'Updated policy text.',
        ]);
    }

    public function test_the_content_is_required_for_every_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => [['locale' => 'en', 'content' => 'Only English']],
        ]))->assertStatus(422)->assertJsonValidationErrors('translations');

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => $this->translations('English', ''),
        ]))->assertStatus(422)->assertJsonValidationErrors('translations.1.content');
    }

    // ---------------------------------------------------------------------- ordering

    public function test_the_list_is_ordered_by_sort_order_then_id(): void
    {
        $this->actingAsAdmin();

        foreach ([[5, 'Third'], [1, 'First'], [1, 'Second']] as [$sort, $label]) {
            $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
                'sort_order' => $sort,
                'translations' => $this->translations("{$label} policy"),
            ]))->assertCreated();
        }

        $this->assertSame(
            ['First policy', 'Second policy', 'Third policy'],
            $this->getJson('/api/admin/v1/privacy-policies')->assertOk()->json('data.*.content'),
        );
    }

    public function test_the_search_matches_the_policy_content(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => $this->translations('Cookies are used for sessions.'),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => $this->translations('Location data is never collected.'),
        ]))->assertCreated();

        $search = json_encode([
            'searchKey' => 'Cookies',
            'columns' => [],
            'searchInTranslations' => true,
            'filterTranslationByLocale' => false,
        ]);

        $this->getJson('/api/admin/v1/privacy-policies?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Cookies are used for sessions.');
    }

    public function test_the_list_can_be_filtered_by_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'service_id' => $this->service->id,
            'translations' => $this->translations('Wallet specific policy'),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'translations' => $this->translations('General policy'),
        ]))->assertCreated();

        $this->getJson('/api/admin/v1/privacy-policies?'.http_build_query([
            'filterColumns' => [
                'columns' => [[
                    'column' => 'service_id',
                    'opreator' => '=',
                    'value' => $this->service->id,
                    'searchType' => 'where',
                ]],
            ],
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Wallet specific policy');
    }

    // --------------------------------------------------------------------- lifecycle

    public function test_the_status_can_be_toggled(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/privacy-policies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/admin/v1/privacy-policies/{$id}/status", ['status' => false])
            ->assertOk()
            ->assertJsonPath('data.status', false);
    }

    public function test_a_soft_deleted_policy_keeps_its_content_and_can_be_restored(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/privacy-policies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/admin/v1/privacy-policies/{$id}")->assertNoContent();

        $this->assertSoftDeleted('privacy_policies', ['id' => $id]);
        $this->assertDatabaseCount('privacy_policy_translations', 2);

        $this->getJson("/api/admin/v1/privacy-policies/{$id}")->assertNotFound();

        $this->postJson("/api/admin/v1/privacy-policies/{$id}/restore")->assertOk();

        $this->getJson("/api/admin/v1/privacy-policies/{$id}")->assertOk();
    }

    public function test_force_deleting_a_policy_removes_its_content(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/privacy-policies', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/admin/v1/privacy-policies/{$id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/privacy-policies/{$id}/force")->assertNoContent();

        $this->assertDatabaseMissing('privacy_policies', ['id' => $id]);
        $this->assertDatabaseCount('privacy_policy_translations', 0);
    }

    public function test_several_policies_can_be_deleted_at_once(): void
    {
        $this->actingAsAdmin();

        $ids = collect([1, 2])->map(fn (int $index) => $this->postJson(
            '/api/admin/v1/privacy-policies',
            $this->payload(['translations' => $this->translations("Policy {$index}")]),
        )->assertCreated()->json('data.id'))->all();

        $this->postJson('/api/admin/v1/privacy-policies/delete-multiple', ['ids' => $ids])->assertOk();

        foreach ($ids as $id) {
            $this->assertSoftDeleted('privacy_policies', ['id' => $id]);
        }
    }

    public function test_removing_the_service_category_a_policy_pointed_at_makes_it_general(): void
    {
        $this->actingAsAdmin(['privacy-policy.view', 'privacy-policy.create', 'service_categories.delete']);

        $policyId = $this->postJson('/api/admin/v1/privacy-policies', $this->payload([
            'service_id' => $this->service->id,
        ]))->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}/force")->assertNoContent();

        $this->assertDatabaseHas('privacy_policies', ['id' => $policyId, 'service_id' => null]);
    }

    // -------------------------------------------------------------------- validation

    public function test_the_sort_order_is_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/privacy-policies', $this->payload(['sort_order' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_order');
    }

    // ------------------------------------------------------------------- permissions

    public function test_an_admin_without_the_privacy_permissions_is_refused_everywhere(): void
    {
        $this->actingAsAdmin(['service_categories.view']);

        $this->getJson('/api/admin/v1/privacy-policies')->assertForbidden();
        $this->postJson('/api/admin/v1/privacy-policies', $this->payload())->assertForbidden();
        $this->postJson('/api/admin/v1/privacy-policies/delete-multiple', ['ids' => [1]])->assertForbidden();
        $this->patchJson('/api/admin/v1/privacy-policies/1/status', ['status' => true])->assertForbidden();
        $this->deleteJson('/api/admin/v1/privacy-policies/1')->assertForbidden();
    }

    public function test_the_privacy_permissions_are_seeded(): void
    {
        $this->seed(\Database\Seeders\Admin\AdminPermissionSeeder::class);

        foreach (['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'] as $action) {
            $this->assertDatabaseHas('permissions', [
                'name' => "privacy-policy.{$action}",
                'guard_name' => 'admin_api',
                'group_name' => 'privacy-policy',
            ]);
        }
    }
}
