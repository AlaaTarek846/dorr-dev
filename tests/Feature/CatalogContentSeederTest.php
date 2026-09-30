<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Flag;
use App\Models\Language;
use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Database\Seeders\General\FaqSeeder;
use Database\Seeders\General\LegalPageSeeder;
use Database\Seeders\General\ServiceCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class CatalogContentSeederTest extends TestCase
{
    use RefreshDatabase;

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
    }

    // ------------------------------------------------------------- faq

    public function test_it_seeds_faqs_with_both_locales(): void
    {
        $this->seed(FaqSeeder::class);

        $this->assertGreaterThan(0, Faq::count());

        Faq::with('translations')->get()->each(function (Faq $faq): void {
            $locales = $faq->translations->pluck('locale')->sort()->values();

            $this->assertSame(['ar', 'en'], $locales->all());

            foreach ($faq->translations as $translation) {
                $this->assertNotEmpty($translation->question);
                $this->assertNotEmpty($translation->answer);
            }

            $english = $faq->translations->firstWhere('locale', 'en');
            $arabic = $faq->translations->firstWhere('locale', 'ar');

            $this->assertNotSame(
                $english->question,
                $arabic->question,
                'Each locale should carry its own question.'
            );
        });
    }

    public function test_it_orders_faqs_by_sort_order(): void
    {
        $this->seed(FaqSeeder::class);

        $orders = Faq::orderBy('sort_order')->pluck('sort_order')->all();

        $this->assertSame(range(1, count($orders)), $orders);
    }

    public function test_it_links_service_specific_faqs_to_a_service_category(): void
    {
        $ride = ServiceCategory::create([
            'module_name' => 'passenger_ride', 'status' => true, 'sort_order' => 1,
        ]);

        $this->seed(FaqSeeder::class);

        $this->assertGreaterThan(0, Faq::where('service_id', $ride->id)->count());

        $this->assertGreaterThan(
            0,
            Faq::whereNull('service_id')->count(),
            'General FAQs should stay without a service.'
        );
    }

    public function test_it_resolves_nothing_to_null_when_the_service_is_missing(): void
    {
        $this->seed(FaqSeeder::class);

        $this->assertSame(0, Faq::where('service_id', 999)->count());
        $this->assertFalse(
            Faq::get()->contains(fn (Faq $faq) => $faq->service_id !== null
                && ! ServiceCategory::whereKey($faq->service_id)->exists())
        );
    }

    public function test_faq_seeding_is_idempotent(): void
    {
        $this->seed(FaqSeeder::class);
        $count = Faq::count();
        $translations = Faq::with('translations')->get()
            ->sum(fn (Faq $faq) => $faq->translations->count());

        $this->seed(FaqSeeder::class);

        $this->assertSame($count, Faq::count());
        $this->assertSame(
            $translations,
            Faq::with('translations')->get()->sum(fn (Faq $faq) => $faq->translations->count())
        );
    }

    public function test_faq_seeding_updates_content_in_place(): void
    {
        $this->seed(FaqSeeder::class);

        $faq = Faq::with('translations')->orderBy('sort_order')->firstOrFail();
        $before = $faq->id;

        $this->seed(FaqSeeder::class);

        $this->assertSame($before, Faq::orderBy('sort_order')->firstOrFail()->id);
    }

    public function test_it_keeps_a_disabled_faq_disabled(): void
    {
        $this->seed(FaqSeeder::class);

        $this->assertTrue(
            Faq::where('status', false)->exists(),
            'The seeder should exercise the inactive state as well.'
        );
    }

    // ------------------------------------------------------------- legal pages

    public function test_it_seeds_rich_text_legal_pages_for_every_type(): void
    {
        $this->seed(LegalPageSeeder::class);

        foreach (['privacy', 'term'] as $type) {
            $page = LegalPage::where('type', $type)->with('translations')->firstOrFail();

            $this->assertCount(2, $page->translations);

            foreach ($page->translations as $translation) {
                $this->assertStringContainsString('<h2>', $translation->content);
                $this->assertStringContainsString('<ul>', $translation->content);
                $this->assertStringContainsString('<li>', $translation->content);
            }
        }
    }

    public function test_legal_pages_are_platform_wide(): void
    {
        $this->seed(LegalPageSeeder::class);

        $this->assertTrue(LegalPage::whereNull('service_id')->exists());
    }

    public function test_legal_page_seeding_is_idempotent(): void
    {
        $this->seed(LegalPageSeeder::class);
        $count = LegalPage::count();

        $this->seed(LegalPageSeeder::class);

        $this->assertSame($count, LegalPage::count());
        $this->assertSame(2, LegalPage::count());
        $this->assertSame(2, LegalPage::firstOrFail()->translations()->count());
    }

    public function test_seeded_content_passes_the_rich_text_allow_list(): void
    {
        $this->seed([FaqSeeder::class, LegalPageSeeder::class]);

        $allowed = '<p><br><strong><b><em><i><u><s><strike><del><ins><mark><small>'
            .'<sub><sup><code><pre><blockquote><ul><ol><li><h2><h3><h4><h5><h6>'
            .'<a><img><hr><span><div><table><caption><thead><tbody><tfoot><tr><th><td>'
            .'<figure><figcaption>';

        $contents = LegalPage::with('translations')->get()
            ->flatMap(fn (LegalPage $page) => $page->translations->pluck('content'));

        $contents->each(function (string $content) use ($allowed): void {
            $stripped = strip_tags($content, $allowed);

            $this->assertSame(
                preg_replace('/\s+/', ' ', $content),
                preg_replace('/\s+/', ' ', $stripped),
                'Seeded content must only use allow-listed tags.'
            );
        });
    }

    public function test_every_referenced_service_module_exists(): void
    {
        $this->seed(ServiceCategoriesSeeder::class);

        $seeder = new FaqSeeder;

        $reflection = new ReflectionMethod($seeder, 'rows');
        $reflection->setAccessible(true);

        $modules = collect($reflection->invoke($seeder))
            ->pluck('service')
            ->filter()
            ->unique()
            ->values();

        $this->assertGreaterThan(0, $modules->count());

        $available = ServiceCategory::query()->pluck('module_name')->all();

        foreach ($modules as $module) {
            $this->assertContains(
                $module,
                $available,
                "Seeded FAQ references service module [{$module}], which no seeder creates."
            );
        }
    }

    public function test_it_is_registered_in_the_main_database_seeder(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString(FaqSeeder::class, $source);
        $this->assertStringContainsString(LegalPageSeeder::class, $source);
    }
}
