<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Freelance\Database\Seeders\FreelanceLegalPageSeeder;
use Tests\TestCase;

/** `GET freelance/v1/legal-pages`: the Freelance service's privacy policy and terms, public, with the general page as fallback. */
class FreelanceLegalPageTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ServiceCategory
    {
        return ServiceCategory::query()->create(['module_name' => 'freelance', 'requires_provider' => false, 'status' => true, 'sort_order' => 35]);
    }

    private function page(string $type, ?int $serviceId, string $content): LegalPage
    {
        $page = LegalPage::query()->create(['type' => $type, 'service_id' => $serviceId, 'status' => true]);
        $page->translations()->create(['locale' => 'en', 'content' => $content]);

        return $page;
    }

    public function test_the_seeder_creates_the_freelance_pages_once(): void
    {
        $service = $this->service();

        $this->seed(FreelanceLegalPageSeeder::class);
        $this->seed(FreelanceLegalPageSeeder::class);

        foreach (['privacy', 'term'] as $type) {
            $this->assertSame(1, LegalPage::query()->where('type', $type)->where('service_id', $service->id)->where('status', true)->count());
        }
    }

    public function test_the_service_page_is_returned_without_signing_in(): void
    {
        $service = $this->service();
        $this->page('privacy', null, 'General privacy');
        $this->page('privacy', $service->id, 'Freelance privacy');

        $this->getJson('/api/freelance/v1/legal-pages?type=privacy')
            ->assertOk()
            ->assertJsonPath('data.content', 'Freelance privacy');
    }

    public function test_a_missing_service_page_falls_back_to_the_general_one(): void
    {
        $this->service();
        $this->page('term', null, 'General terms');

        $this->getJson('/api/freelance/v1/legal-pages?type=term')
            ->assertOk()
            ->assertJsonPath('data.content', 'General terms');
    }

    public function test_the_type_is_required_and_checked(): void
    {
        $this->getJson('/api/freelance/v1/legal-pages')->assertStatus(422);
        $this->getJson('/api/freelance/v1/legal-pages?type=other')->assertStatus(422);
    }
}
