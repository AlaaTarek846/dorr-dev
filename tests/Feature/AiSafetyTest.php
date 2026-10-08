<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Safety\RiskAssessment;
use Modules\AI\Safety\RiskClassifier;
use Modules\AI\Safety\SafetyPolicyEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR AI safety (spec 350–362): the RiskClassifier classifies before any answer, and the
 * SafetyPolicyEngine adds the approved referral and disclaimer itself — the model never words them
 * and can't skip them.
 */
class AiSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake model says when it is asked to classify, and when it answers. */
    private ?string $classification = null;

    private string $reply = 'An answer.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        // AiChatUsageGuard 402s ("no_plan") unless an active trial plan exists to start the user on.
        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-safety-test', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 60, 'cooldown_minutes' => 5]);

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake(['api.openai.com/v1/chat/completions' => function ($request) {
            $system = (string) ($request['messages'][0]['content'] ?? '');
            $content = str_starts_with($system, 'You classify a request') ? ($this->classification ?? 'not json') : $this->reply;

            return Http::response(['choices' => [['message' => ['content' => $content]]]]);
        }]);
    }

    public function test_the_rules_classify_on_their_own_in_arabic_and_english(): void
    {
        $rules = app(RiskClassifier::class);

        $medical = $rules->byRules('عندي صداع من يومين وباخد بنادول، أزود الجرعة؟');
        $this->assertSame([RiskAssessment::MEDICINE, true], [$medical->domain, $medical->specific]);

        $religion = $rules->byRules('ما حكم صلاة الجمعة في السفر؟');
        $this->assertSame([RiskAssessment::RELIGION, false], [$religion->domain, $religion->specific]);

        $wall = $rules->byRules('عايز أشيل جدار حامل في الصالة عشان أوسعها');
        $this->assertSame([RiskAssessment::ENGINEERING, true], [$wall->domain, $wall->specific]);

        $code = $rules->byRules('Write a PHP function that charges the customer payments in production');
        $this->assertSame([RiskAssessment::CODE, true], [$code->domain, $code->specific]);

        $lawyer = $rules->byRules('My landlord kept my deposit, can I sue him?');
        $this->assertSame([RiskAssessment::LAW, true], [$lawyer->domain, $lawyer->specific]);

        $this->assertNull($rules->byRules('What a lovely painting, any ideas for a birthday party?')->domain);
    }

    public function test_the_engine_adds_the_approved_texts_and_removes_optional_wording(): void
    {
        $engine = app(SafetyPolicyEngine::class);
        $risk = new RiskAssessment(RiskAssessment::LAW, true);

        $done = $engine->finish('The deposit must be returned within 30 days. Consult a lawyer if needed. '.__('ai.safety.notice.law'), $risk);
        $this->assertStringContainsString('Consult a lawyer.', $done['text']);
        $this->assertStringNotContainsString('if needed', $done['text']);
        // The fixed sentence shows once — in the card, not repeated by the model.
        $this->assertStringNotContainsString(__('ai.safety.notice.law'), $done['text']);
        $this->assertSame(__('ai.safety.notice.law'), $done['safety']['notice']);
        $this->assertSame(__('ai.safety.disclaimer'), $done['safety']['disclaimer']);

        // A general high-stakes question: the disclaimer, no referral.
        $general = $engine->finish('Friday prayer is…', new RiskAssessment(RiskAssessment::RELIGION, false));
        $this->assertNull($general['safety']['notice']);
        $this->assertNotNull($general['safety']['disclaimer']);

        // Educational code: nothing to add. Production code: "not tested" + review, from the system.
        $this->assertNull($engine->finish('```php echo 1; ```', new RiskAssessment(RiskAssessment::CODE, false))['safety']);
        $this->assertSame(__('ai.safety.notice.code'), $engine->finish('```php …```', new RiskAssessment(RiskAssessment::CODE, true))['safety']['notice']);

        // Nothing sensitive: untouched.
        $this->assertSame(['text' => 'Hello', 'safety' => null], $engine->finish('Hello', RiskAssessment::none()));

        // The fixed Arabic sentences are exactly the approved ones (spec 352, 358).
        app()->setLocale('ar');
        $this->assertSame('هذه معلومة عامة، استشر محاميًا مرخّصًا في دولتك.', __('ai.safety.notice.law'));
        $this->assertSame('هذا اجتهاد عام، استشر عالم دين موثوق لحالتك الخاصة.', __('ai.safety.notice.religion'));
        $this->assertSame('هذه معلومة طبية عامة، استشر طبيبًا أو مختصًا صحيًا مرخّصًا لحالتك الخاصة.', __('ai.safety.notice.medicine'));
        $this->assertSame('هذا تصور تصميمي عام، ولازم يُراجَع ويُعتمد من مهندس مرخّص قبل التنفيذ لأنه يمس السلامة الإنشائية.', __('ai.safety.notice.engineering'));
    }

    public function test_dorr_ai_chat_classifies_first_instructs_the_model_and_adds_the_referral_itself(): void
    {
        Sanctum::actingAs($this->user, [], 'user_api');
        $conversation = $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');

        // The classification call says "a personal medical case"; the answer forgets any referral.
        $this->classification = '{"domain": "medicine", "specific": true}';
        $this->reply = 'Headaches can have many causes, such as dehydration or stress.';
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation}/messages", ['message' => 'I have had a headache for two days, should I double my dose?'])
            ->assertOk()
            ->assertJsonPath('data.assistant_message.safety.domain', 'medicine')
            ->assertJsonPath('data.assistant_message.safety.notice', __('ai.safety.notice.medicine'))
            ->assertJsonPath('data.assistant_message.content', 'Headaches can have many causes, such as dehydration or stress.'."\n\n".__('ai.safety.notice.medicine')."\n\n".__('ai.safety.disclaimer'));

        // Classified before answering, and the answer was told not to diagnose.
        $calls = collect(Http::recorded())->map(fn ($pair) => $pair[0]['messages'] ?? [])->values();
        $this->assertStringStartsWith('You classify a request', $calls[0][0]['content']);
        $this->assertTrue(collect($calls[1])->contains(fn ($m) => str_contains($m['content'], 'do NOT give a final ruling, a fatwa, a decisive legal opinion, a diagnosis')));
    }

    public function test_when_the_classifier_cannot_answer_the_rules_still_decide(): void
    {
        Sanctum::actingAs($this->user, [], 'user_api');
        $conversation = $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');

        $this->classification = 'sorry, I cannot';
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation}/messages", ['message' => 'عقد الإيجار بتاعي فيه شرط جزائي، المؤجر رفع عليّا قضية، أعمل إيه؟'])
            ->assertOk()
            ->assertJsonPath('data.assistant_message.safety.domain', 'law')
            ->assertJsonPath('data.assistant_message.safety.specific', true);

        // An everyday question: no card, no added text.
        $this->classification = '{"domain": "none", "specific": false}';
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation}/messages", ['message' => 'Suggest a name for my bakery'])
            ->assertOk()
            ->assertJsonPath('data.assistant_message.safety', null)
            ->assertJsonPath('data.assistant_message.content', 'An answer.');
    }
}
