<?php

namespace Tests\Feature;

use App\Models\Faq;
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

/**
 * The translation sync was generalised to read the translation model $fillable
 * instead of assuming a single "name" column. These tests pin both shapes.
 */
class CatalogTranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);

        foreach ([
            ['en', 'ltr', true, true],
            ['ar', 'rtl', false, false],
        ] as [$code, $direction, $website, $dashboard]) {
            Language::create([
                'code' => $code, 'direction' => $direction,
                'is_default_website' => $website, 'is_default_dashboard' => $dashboard,
                'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id,
            ]);
        }
    }

    private function actingAsAdmin(): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active',
        ]);

        $permissions = [
            'faqs.view', 'faqs.create', 'faqs.update',
            'service_categories.view', 'service_categories.create', 'service_categories.update',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_a_name_only_entity_still_resolves_name_as_its_only_translatable_field(): void
    {
        $category = new ServiceCategory;

        $this->assertSame(['name'], $category->translatableFields());
    }

    public function test_a_multi_field_entity_resolves_every_translatable_field(): void
    {
        $this->assertSame(['question', 'answer'], (new Faq)->translatableFields());
        $this->assertSame(['content'], (new PrivacyPolicy)->translatableFields());
    }

    public function test_a_service_category_still_saves_its_name_per_locale(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/service-categories', [
            'sort_order' => 0,
            'status' => true,
            'translations' => [
                ['locale' => 'en', 'name' => 'Wallet'],
                ['locale' => 'ar', 'name' => 'المحفظة'],
            ],
        ])->assertCreated()->assertJsonPath('data.name', 'Wallet');

        $this->assertDatabaseHas('service_category_translations', [
            'service_category_id' => ServiceCategory::query()->firstOrFail()->id,
            'locale' => 'ar',
            'name' => 'المحفظة',
        ]);
    }

    public function test_a_service_category_name_can_be_updated_in_place(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/service-categories', [
            'sort_order' => 0,
            'status' => true,
            'translations' => [
                ['locale' => 'en', 'name' => 'Wallet'],
                ['locale' => 'ar', 'name' => 'المحفظة'],
            ],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/service-categories/{$id}", [
            'sort_order' => 0,
            'status' => true,
            'translations' => [
                ['locale' => 'en', 'name' => 'Wallet renamed'],
                ['locale' => 'ar', 'name' => 'المحفظة'],
            ],
        ])->assertOk()->assertJsonPath('data.name', 'Wallet renamed');

        $this->assertDatabaseCount('service_category_translations', 2);
        $this->assertDatabaseHas('service_category_translations', [
            'service_category_id' => $id, 'locale' => 'en', 'name' => 'Wallet renamed',
        ]);
    }

    public function test_a_translation_table_holds_exactly_one_row_per_locale(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', [
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                ['locale' => 'en', 'question' => 'First question', 'answer' => 'First answer'],
                ['locale' => 'ar', 'question' => 'سؤال أول', 'answer' => 'إجابة أولى'],
            ],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/faqs/{$id}", [
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                ['locale' => 'en', 'question' => 'Second question', 'answer' => 'Second answer'],
                ['locale' => 'ar', 'question' => 'سؤال ثان', 'answer' => 'إجابة ثانية'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('faq_translations', 2);
    }

    public function test_the_translated_helper_falls_back_to_another_locale(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/v1/faqs', [
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                ['locale' => 'en', 'question' => 'English question', 'answer' => 'English answer'],
                ['locale' => 'ar', 'question' => 'سؤال', 'answer' => 'إجابة'],
            ],
        ])->assertCreated()->json('data.id');

        $this->app->setLocale('fr');

        $this->getJson("/api/admin/v1/faqs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.question', 'English question');
    }
}
