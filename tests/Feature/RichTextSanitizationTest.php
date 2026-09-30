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

class RichTextSanitizationTest extends TestCase
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

    private function actingAsAdmin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active',
        ]);

        $permissions = [
            'privacy-policy.list', 'privacy-policy.create', 'privacy-policy.update',
            'faqs.list', 'faqs.create',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin->givePermissionTo($permissions);

        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /**
     * @param  array<int, string>  $arabic
     * @param  array<int, string>  $english
     */
    private function policyPayload(array $arabic, array $english, array $overrides = []): array
    {
        return array_merge([
            'service_id' => null,
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                ['locale' => 'ar', 'content' => $arabic[0]],
                ['locale' => 'en', 'content' => $english[0]],
            ],
        ], $overrides);
    }

    private function createPolicy(array $arabic, array $english, array $overrides = [])
    {
        return $this->postJson(
            '/api/admin/v1/privacy-policies',
            $this->policyPayload($arabic, $english, $overrides)
        );
    }

    // ------------------------------------------------------------- allow-list

    public function test_it_keeps_safe_formatting(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(
            ['<p>سياسة <strong>خاصة</strong> و<u>رابط</u></p>'],
            ['<h2>Title</h2><ul><li>One</li><li>Two</li></ul>']
        )->assertCreated();

        $policy = PrivacyPolicy::firstOrFail();

        $this->assertStringContainsString('<strong>خاصة</strong>', $policy->translations[0]->content);
        $this->assertStringContainsString('<h2>Title</h2>', $policy->translations[1]->content);
        $this->assertStringContainsString('<li>One</li>', $policy->translations[1]->content);
    }

    public function test_it_keeps_links_images_and_alignment(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(
            ['<p><a href="https://example.com/ar">الشروط</a></p>'],
            ['<p style="text-align: center"><img src="https://example.com/a.png">Centered</p>']
        )->assertCreated();

        $translations = PrivacyPolicy::firstOrFail()->translations;

        $this->assertStringContainsString('href="https://example.com/ar"', $translations[0]->content);
        $this->assertStringContainsString('src="https://example.com/a.png"', $translations[1]->content);
        $this->assertMatchesRegularExpression(
            '/text-align:\s*center/',
            $translations[1]->content
        );
    }

    // ------------------------------------------------------------- stripping

    public function test_it_strips_event_handlers_and_unknown_tags(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(
            ['<p onclick="steal()">نص آمن</p><script>alert("xss")</script>'],
            ['<p onmouseover="x()">Safe text</p><iframe src="https://evil.test"></iframe>']
        )->assertCreated();

        $translations = PrivacyPolicy::firstOrFail()->translations;

        foreach ($translations as $translation) {
            $this->assertStringNotContainsString('onclick', $translation->content);
            $this->assertStringNotContainsString('onmouseover', $translation->content);
            $this->assertStringNotContainsString('<script', $translation->content);
            $this->assertStringNotContainsString('<iframe', $translation->content);
        }

        $this->assertStringContainsString('نص آمن', $translations[0]->content);
        $this->assertStringContainsString('Safe text', $translations[1]->content);
    }

    public function test_it_rejects_javascript_urls(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(
            ['<p><a href="javascript:alert(1)">اضغط</a></p>'],
            ['<p><a href="https://example.com">ok</a></p>']
        )->assertCreated();

        $translations = PrivacyPolicy::firstOrFail()->translations;

        $this->assertStringNotContainsString('javascript:', $translations[0]->content);
        $this->assertStringContainsString('اضغط', $translations[0]->content);
        $this->assertStringContainsString('href="https://example.com"', $translations[1]->content);
    }

    public function test_it_drops_non_alignment_styles(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(
            ['<p style="background: url(javascript:alert(1))">نص</p>'],
            ['<p style="color: red">Colored</p>']
        )->assertCreated();

        $translations = PrivacyPolicy::firstOrFail()->translations;

        $this->assertStringNotContainsString('javascript', $translations[0]->content);
        $this->assertStringNotContainsString('style', $translations[1]->content);
    }

    // ------------------------------------------------------------- empty markup

    public function test_visually_empty_markup_fails_required_validation(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(['<p></p>'], ['<p><br></p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['translations.0.content', 'translations.1.content']);

        $this->assertDatabaseCount('privacy_policies', 0);
    }

    public function test_markup_only_content_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->createPolicy(['<p>&nbsp;</p>'], ['<p>  </p>'])
            ->assertStatus(422);

        $this->assertDatabaseCount('privacy_policies', 0);
    }

    public function test_content_still_validates_with_a_service_relation(): void
    {
        $this->actingAsAdmin();

        $service = ServiceCategory::create([
            'module_name' => 'general_services', 'status' => true, 'sort_order' => 0,
        ]);

        $this->createPolicy(['<p>نص</p>'], ['<p>text</p>'], ['service_id' => $service->id])
            ->assertCreated();

        $this->assertSame($service->id, PrivacyPolicy::firstOrFail()->service_id);
    }

    // ------------------------------------------------------------- faq answer

    public function test_faq_answer_is_sanitized(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', [
            'service_id' => null,
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                [
                    'locale' => 'ar',
                    'question' => 'سؤال',
                    'answer' => '<p onmouseover="x()">إجابة</p><script>bad()</script>',
                ],
                ['locale' => 'en', 'question' => 'Question', 'answer' => '<p>Answer</p>'],
            ],
        ])->assertCreated();

        $answer = Faq::firstOrFail()->translations[0]->answer;

        $this->assertStringNotContainsString('onmouseover', $answer);
        $this->assertStringNotContainsString('<script', $answer);
        $this->assertStringContainsString('إجابة', $answer);
    }

    public function test_faq_question_is_left_untouched(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', [
            'service_id' => null,
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                [
                    'locale' => 'ar',
                    'question' => 'سؤال <b>عادي</b>',
                    'answer' => '<p>إجابة</p>',
                ],
                ['locale' => 'en', 'question' => 'Plain question', 'answer' => '<p>Answer</p>'],
            ],
        ])->assertCreated();

        $this->assertSame('سؤال <b>عادي</b>', Faq::firstOrFail()->translations[0]->question);
    }

    public function test_empty_faq_answer_fails_validation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/faqs', [
            'service_id' => null,
            'status' => true,
            'sort_order' => 0,
            'translations' => [
                ['locale' => 'ar', 'question' => 'سؤال', 'answer' => '<p></p>'],
                ['locale' => 'en', 'question' => 'Question', 'answer' => '<p></p>'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('faqs', 0);
    }
}
