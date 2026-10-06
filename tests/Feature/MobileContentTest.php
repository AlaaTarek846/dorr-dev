<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Flag;
use App\Models\Language;
use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileContentTest extends TestCase
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

    private function makeFaq(array $attributes, string $question, string $answer = 'Answer'): Faq
    {
        $faq = Faq::create($attributes);

        $faq->translations()->createMany([
            ['locale' => 'en', 'question' => $question, 'answer' => $answer],
            ['locale' => 'ar', 'question' => 'ar:'.$question, 'answer' => 'ar:'.$answer],
        ]);

        return $faq;
    }

    private function makeLegalPage(array $attributes, string $content): LegalPage
    {
        $page = LegalPage::create($attributes);

        $page->translations()->createMany([
            ['locale' => 'en', 'content' => $content],
            ['locale' => 'ar', 'content' => 'ar:'.$content],
        ]);

        return $page;
    }

    public function test_faqs_endpoint_returns_only_active_general_faqs(): void
    {
        $this->makeFaq(['service_id' => null, 'status' => true, 'sort_order' => 2], 'General two');
        $this->makeFaq(['service_id' => null, 'status' => true, 'sort_order' => 1], 'General one');
        $this->makeFaq(['service_id' => null, 'status' => false, 'sort_order' => 0], 'Hidden general');
        $this->makeFaq(['service_id' => $this->service->id, 'status' => true, 'sort_order' => 0], 'Service faq');

        $this->getJson('/api/mobile/v1/faqs', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.question', 'General one')
            ->assertJsonPath('data.1.question', 'General two');
    }

    public function test_faqs_endpoint_localizes_the_question(): void
    {
        $this->makeFaq(['service_id' => null, 'status' => true, 'sort_order' => 0], 'General one');

        $this->getJson('/api/mobile/v1/faqs', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.question', 'ar:General one');
    }

    public function test_legal_pages_endpoint_requires_a_type(): void
    {
        $this->getJson('/api/mobile/v1/legal-pages', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->getJson('/api/mobile/v1/legal-pages?type=jobs', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_legal_pages_endpoint_returns_the_general_privacy_page(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => $this->service->id, 'status' => true], 'Service policy');
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'General policy');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data', ['content' => 'General policy']);
    }

    public function test_legal_pages_endpoint_returns_only_the_content_in_the_request_locale(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'General policy');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data', ['content' => 'ar:General policy']);
    }

    public function test_legal_pages_endpoint_returns_the_service_specific_page(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'General policy');
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => $this->service->id, 'status' => true], 'Service policy');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy&service_id='.$this->service->id, ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.content', 'Service policy');
    }

    public function test_legal_pages_endpoint_distinguishes_by_type(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'Privacy policy');
        $this->makeLegalPage(['type' => 'term', 'service_id' => null, 'status' => true], 'Terms');

        $this->getJson('/api/mobile/v1/legal-pages?type=term', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.content', 'Terms');
    }

    public function test_legal_pages_endpoint_prefers_the_first_general_page(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'First');
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'Later');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.content', 'First');
    }

    public function test_legal_pages_endpoint_returns_empty_when_no_general_page_exists(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => $this->service->id, 'status' => true], 'Service policy');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_legal_pages_endpoint_localizes_the_content(): void
    {
        $this->makeLegalPage(['type' => 'privacy', 'service_id' => null, 'status' => true], 'General policy');

        $this->getJson('/api/mobile/v1/legal-pages?type=privacy', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.content', 'ar:General policy');
    }
}
