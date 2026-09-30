<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Flag;
use App\Models\Language;
use App\Models\PrivacyPolicy;
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

    private function makePolicy(array $attributes, string $content): PrivacyPolicy
    {
        $policy = PrivacyPolicy::create($attributes);

        $policy->translations()->createMany([
            ['locale' => 'en', 'content' => $content],
            ['locale' => 'ar', 'content' => 'ar:'.$content],
        ]);

        return $policy;
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

    public function test_privacy_policy_endpoint_returns_the_general_policy(): void
    {
        $this->makePolicy(['service_id' => $this->service->id, 'status' => true, 'sort_order' => 0], 'Service policy');
        $this->makePolicy(['service_id' => null, 'status' => true, 'sort_order' => 0], 'General policy');

        $this->getJson('/api/mobile/v1/privacy-policy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.content', 'General policy')
            ->assertJsonPath('data.service_id', null);
    }

    public function test_privacy_policy_endpoint_prefers_the_first_general_policy(): void
    {
        $this->makePolicy(['service_id' => null, 'status' => true, 'sort_order' => 5], 'Later');
        $this->makePolicy(['service_id' => null, 'status' => true, 'sort_order' => 1], 'First');

        $this->getJson('/api/mobile/v1/privacy-policy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.content', 'First');
    }

    public function test_privacy_policy_endpoint_returns_empty_when_no_general_policy_exists(): void
    {
        $this->makePolicy(['service_id' => $this->service->id, 'status' => true, 'sort_order' => 0], 'Service policy');

        $this->getJson('/api/mobile/v1/privacy-policy', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
