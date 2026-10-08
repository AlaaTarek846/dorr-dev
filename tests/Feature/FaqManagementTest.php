<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Flag;
use App\Models\Language;
use App\Models\ServiceCategory;
use Database\Seeders\Admin\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FaqManagementTest extends TestCase
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
     * Every storable locale must be supplied, as the catalog requires.
     *
     * @param  array<string, string>  $overrides  english => [question, answer]
     * @return list<array<string, string>>
     */
    private function translations(array $en, ?array $ar = null): array
    {
        return [
            ['locale' => 'en', 'question' => $en[0], 'answer' => $en[1]],
            [
                'locale' => 'ar',
                'question' => $ar[0] ?? $en[0],
                'answer' => $ar[1] ?? $en[1],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => null,
            'status' => true,
            'translations' => $this->translations(
                ['How do I reset my password?', 'Use the reset link.'],
                ['كيف أعيد تعيين كلمة المرور؟', 'استخدم رابط إعادة التعيين.'],
            ),
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function permissions(): array
    {
        return [
            'faqs.view', 'faqs.create', 'faqs.update', 'faqs.delete',
            'faqs.change-status', 'faqs.multiple-delete',
        ];
    }

    private function actingAsAdmin(?array $permissions = null): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active',
        ]);

        $permissions ??= $this->permissions();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    // ------------------------------------------------------------- general vs service

    public function test_a_faq_without_a_service_is_general(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/faqs', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.service', null);

        $this->assertDatabaseHas('faqs', ['service_id' => null, 'sort_order' => 1]);
    }

    public function test_a_faq_can_be_linked_to_a_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload(['service_id' => $this->service->id]))
            ->assertCreated()
            ->assertJsonPath('data.service_id', $this->service->id)
            ->assertJsonPath('data.service.id', $this->service->id);

        $this->assertDatabaseHas('faqs', ['service_id' => $this->service->id]);
    }

    public function test_a_faq_can_be_moved_from_a_service_back_to_general(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', $this->payload(['service_id' => $this->service->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/admin/v1/faqs/{$id}", $this->payload(['service_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.service_id', null);
    }

    public function test_a_faq_cannot_point_at_a_service_that_does_not_exist(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload(['service_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    }

    // ------------------------------------------------------------------ translations

    public function test_it_stores_a_translation_per_locale_with_question_and_answer(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload())
            ->assertCreated()
            ->assertJsonCount(2, 'data.translations')
            ->assertJsonPath('data.question', 'How do I reset my password?')
            ->assertJsonPath('data.answer', 'Use the reset link.');

        $this->assertDatabaseHas('faq_translations', [
            'faq_id' => Faq::query()->firstOrFail()->id,
            'locale' => 'ar',
            'question' => 'كيف أعيد تعيين كلمة المرور؟',
            'answer' => 'استخدم رابط إعادة التعيين.',
        ]);
    }

    public function test_a_translation_can_be_updated(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $payload = $this->payload();
        $payload['translations'][0]['answer'] = 'Updated English answer.';

        $this->putJson("/api/admin/v1/faqs/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.answer', 'Updated English answer.');

        $this->assertDatabaseHas('faq_translations', [
            'faq_id' => $id, 'locale' => 'en', 'answer' => 'Updated English answer.',
        ]);
    }

    public function test_question_and_answer_are_both_required_for_every_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => [['locale' => 'en', 'question' => 'Only a question']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['translations', 'translations.0.answer']);

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => [
                ['locale' => 'en', 'question' => 'A question', 'answer' => 'An answer'],
                ['locale' => 'ar', 'question' => 'سؤال', 'answer' => ''],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('translations.1.answer');
    }

    // ---------------------------------------------------------------------- ordering

    private function createFaq(string $question, ?int $serviceId = null): int
    {
        return $this->postJson('/api/admin/v1/faqs', $this->payload([
            'service_id' => $serviceId,
            'translations' => $this->translations([$question, "{$question} answer"]),
        ]))->assertCreated()->json('data.id');
    }

    public function test_the_admin_list_shows_the_newest_first(): void
    {
        $this->actingAsAdmin();

        $this->createFaq('First');
        $this->createFaq('Second');
        $this->createFaq('Third');

        $this->assertSame(
            ['Third', 'Second', 'First'],
            $this->getJson('/api/admin/v1/faqs')->assertOk()->json('data.*.question'),
        );
    }

    public function test_a_new_faq_goes_last_within_its_own_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload(['sort_order' => 50]))->assertCreated();
        $this->createFaq('General two');
        $serviceFaq = $this->createFaq('Service one', $this->service->id);

        $this->assertSame(
            [1, 2],
            Faq::query()->whereNull('service_id')->orderBy('id')->pluck('sort_order')->all(),
        );
        $this->assertDatabaseHas('faqs', ['id' => $serviceFaq, 'sort_order' => 1]);
    }

    public function test_the_ordered_list_is_scoped_to_one_service(): void
    {
        $this->actingAsAdmin();

        $this->createFaq('General A');
        $this->createFaq('General B');
        $this->createFaq('Service A', $this->service->id);

        $this->assertSame(
            ['General A', 'General B'],
            $this->getJson('/api/admin/v1/faqs/ordered')->assertOk()->json('data.*.question'),
        );

        $this->assertSame(
            ['Service A'],
            $this->getJson('/api/admin/v1/faqs/ordered?service_id='.$this->service->id)
                ->assertOk()
                ->json('data.*.question'),
        );
    }

    public function test_the_faqs_of_one_service_can_be_reordered(): void
    {
        $this->actingAsAdmin();

        $first = $this->createFaq('First');
        $second = $this->createFaq('Second');
        $third = $this->createFaq('Third');

        $this->putJson('/api/admin/v1/faqs/reorder', [
            'service_id' => null,
            'ordered_ids' => [$third, $first, $second],
        ])->assertOk();

        $this->assertSame(
            ['Third', 'First', 'Second'],
            $this->getJson('/api/admin/v1/faqs/ordered')->json('data.*.question'),
        );
    }

    public function test_a_reorder_must_cover_exactly_the_faqs_of_that_service(): void
    {
        $this->actingAsAdmin();

        $general = $this->createFaq('General');
        $otherGeneral = $this->createFaq('Other general');
        $serviceFaq = $this->createFaq('Service', $this->service->id);

        $this->putJson('/api/admin/v1/faqs/reorder', [
            'service_id' => null,
            'ordered_ids' => [$general],
        ])->assertStatus(422)->assertJsonValidationErrors('ordered_ids');

        $this->putJson('/api/admin/v1/faqs/reorder', [
            'service_id' => null,
            'ordered_ids' => [$general, $otherGeneral, $serviceFaq],
        ])->assertStatus(422)->assertJsonValidationErrors('ordered_ids');
    }

    public function test_moving_a_faq_to_another_service_puts_it_last_there(): void
    {
        $this->actingAsAdmin();

        $this->createFaq('Service one', $this->service->id);
        $this->createFaq('Service two', $this->service->id);
        $general = $this->createFaq('General');

        $this->putJson("/api/admin/v1/faqs/{$general}", $this->payload(['service_id' => $this->service->id]))
            ->assertOk();

        $this->assertDatabaseHas('faqs', ['id' => $general, 'sort_order' => 3]);
    }

    public function test_editing_a_faq_keeps_its_position(): void
    {
        $this->actingAsAdmin();

        $this->createFaq('First');
        $second = $this->createFaq('Second');

        $this->putJson("/api/admin/v1/faqs/{$second}", $this->payload([
            'translations' => $this->translations(['Second edited', 'Answer']),
        ]))->assertOk();

        $this->assertDatabaseHas('faqs', ['id' => $second, 'sort_order' => 2]);
    }

    public function test_the_search_matches_the_question_and_the_answer(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => $this->translations(['Wallet balance', 'Check the wallet screen']),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => $this->translations(['Refund policy', 'Refunds take 3 days']),
        ]))->assertCreated();

        $search = json_encode([
            'searchKey' => 'Refunds',
            'columns' => [],
            'searchInTranslations' => true,
            'filterTranslationByLocale' => false,
        ]);

        $this->getJson('/api/admin/v1/faqs?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Refund policy');
    }

    public function test_the_list_can_be_filtered_by_service(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'service_id' => $this->service->id,
            'translations' => $this->translations(['Service scoped', 'Answer']),
        ]))->assertCreated();

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => $this->translations(['General scoped', 'Answer']),
        ]))->assertCreated();

        $this->getJson('/api/admin/v1/faqs?'.http_build_query([
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
            ->assertJsonPath('data.0.question', 'Service scoped');
    }

    // --------------------------------------------------------------------- lifecycle

    public function test_the_status_can_be_toggled(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', $this->payload())->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/v1/faqs/{$id}/status", ['status' => false])
            ->assertOk()
            ->assertJsonPath('data.status', false);

        $this->assertDatabaseHas('faqs', ['id' => $id, 'status' => false]);
    }

    public function test_a_soft_deleted_faq_keeps_its_translations_and_can_be_restored(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/v1/faqs/{$id}")->assertNoContent();

        $this->assertSoftDeleted('faqs', ['id' => $id]);
        $this->assertDatabaseCount('faq_translations', 2);

        $this->getJson("/api/admin/v1/faqs/{$id}")->assertNotFound();

        $this->postJson("/api/admin/v1/faqs/{$id}/restore")->assertOk();

        $this->assertDatabaseHas('faqs', ['id' => $id, 'deleted_at' => null]);
        $this->getJson("/api/admin/v1/faqs/{$id}")->assertOk();
    }

    public function test_force_deleting_a_faq_removes_its_translations(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/v1/faqs/{$id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/faqs/{$id}/force")->assertNoContent();

        $this->assertDatabaseMissing('faqs', ['id' => $id]);
        $this->assertDatabaseCount('faq_translations', 0);
    }

    public function test_several_faqs_can_be_deleted_at_once(): void
    {
        $this->actingAsAdmin();

        $ids = collect([1, 2])->map(fn (int $index) => $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => $this->translations(["Q{$index}", "A{$index}"]),
        ]))->assertCreated()->json('data.id'))->all();

        $this->postJson('/api/admin/v1/faqs/delete-multiple', ['ids' => $ids])->assertOk();

        foreach ($ids as $id) {
            $this->assertSoftDeleted('faqs', ['id' => $id]);
        }
    }

    public function test_removing_the_service_category_a_faq_pointed_at_makes_it_general(): void
    {
        $this->actingAsAdmin(['faqs.view', 'faqs.create', 'service_categories.delete']);

        $faqId = $this->postJson('/api/admin/v1/faqs', $this->payload(['service_id' => $this->service->id]))
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}")->assertNoContent();
        $this->deleteJson("/api/admin/v1/service-categories/{$this->service->id}/force")->assertNoContent();

        $this->assertDatabaseHas('faqs', ['id' => $faqId, 'service_id' => null]);
    }

    // -------------------------------------------------------------------- validation

    public function test_the_translations_are_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', $this->payload(['translations' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('translations');

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => [['locale' => 'en', 'question' => 'x', 'answer' => 'A valid answer']],
        ]))->assertStatus(422)->assertJsonValidationErrors('translations.0.question');

        $this->postJson('/api/admin/v1/faqs', $this->payload([
            'translations' => [['locale' => 'en', 'question' => 'A question', 'answer' => 'An answer']],
        ]))->assertStatus(422)->assertJsonValidationErrors('translations');
    }

    // ------------------------------------------------------------------- permissions

    public function test_an_admin_without_the_faq_permissions_is_refused_everywhere(): void
    {
        $this->actingAsAdmin(['service_categories.view']);

        $this->getJson('/api/admin/v1/faqs')->assertForbidden();
        $this->postJson('/api/admin/v1/faqs', $this->payload())->assertForbidden();
        $this->postJson('/api/admin/v1/faqs/delete-multiple', ['ids' => [1]])->assertForbidden();
        $this->patchJson('/api/admin/v1/faqs/1/status', ['status' => true])->assertForbidden();
        $this->deleteJson('/api/admin/v1/faqs/1')->assertForbidden();
    }

    public function test_the_faq_permissions_are_seeded(): void
    {
        $this->seed(AdminPermissionSeeder::class);

        foreach (['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'] as $action) {
            $this->assertDatabaseHas('permissions', [
                'name' => "faqs.{$action}",
                'guard_name' => 'admin_api',
                'group_name' => 'faqs',
            ]);
        }
    }
}
