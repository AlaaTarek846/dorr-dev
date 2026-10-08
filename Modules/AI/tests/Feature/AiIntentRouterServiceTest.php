<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiIntentRouterService;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Step 2 of intent understanding: when the built-in dictionary does not
 * recognise a message, the DEFAULT model is asked once, its (validated)
 * answer is used, and a confident answer is remembered so the same kind
 * of message never costs another model call.
 */
class AiIntentRouterServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AiProviderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml switches the router off for every other test; this class tests it.
        config(['ai.intent_router.enabled' => true]);

        $this->repository = app(AiProviderRepository::class);
        $this->repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $this->repository->setDefault('openai');
    }

    protected function user(): User
    {
        return User::query()->create([
            'name' => 'Router Test User',
            'email' => 'router-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * @param  list<array<string, mixed>|string|null>  $answers  One entry per expected model call (null = provider failure).
     */
    protected function fakeModel(array $answers): MockInterface
    {
        $queue = $answers;

        return $this->mock(AiGateway::class, function (MockInterface $mock) use (&$queue, $answers) {
            $mock->shouldReceive('chat')
                ->times(count($answers))
                ->andReturnUsing(function ($provider) use (&$queue) {
                    $next = array_shift($queue);

                    if ($next === null) {
                        return ['success' => false, 'message' => 'provider down', 'content' => null];
                    }

                    // The default provider's default model must be the one asked.
                    $this->assertSame('gpt-4o-mini', $provider->model);

                    return ['success' => true, 'message' => '', 'content' => is_string($next) ? $next : json_encode($next)];
                });
        });
    }

    protected function neverAskModel(): void
    {
        $this->mock(AiGateway::class, fn (MockInterface $mock) => $mock->shouldNotReceive('chat'));
    }

    protected function decide(string $message, bool $hasAttachment = false, bool $recentImage = false, bool $hasImageContext = false)
    {
        return app(AiIntentRouterService::class)->decide($this->user(), $message, $hasAttachment, $recentImage, $this->repository, $hasImageContext);
    }

    public function test_a_message_the_dictionary_understands_never_calls_the_model(): void
    {
        $this->neverAskModel();

        $decision = $this->decide('اعمل صوره قطه بتضحك');

        $this->assertSame('lexicon', $decision->source);
        $this->assertTrue($decision->isEmpty());
    }

    public function test_small_talk_and_tiny_messages_never_call_the_model(): void
    {
        $this->neverAskModel();

        foreach (['شكرا', 'hello', 'ازيك', 'ok', '؟؟؟', '😀😀😀😀😀😀😀'] as $message) {
            $this->assertTrue($this->decide($message)->isEmpty(), $message);
        }
    }

    public function test_the_router_can_be_switched_off(): void
    {
        config(['ai.intent_router.enabled' => false]);
        $this->neverAskModel();

        $this->assertSame('none', $this->decide('نفسي اشوف بيت على البحر وقت الغروب')->source);
    }

    public function test_an_unknown_phrasing_is_classified_by_the_default_model_and_then_learned(): void
    {
        $this->fakeModel([[
            'intents' => ['image_generation'], 'confidence' => 0.96, 'file_format' => null, 'trigger_phrase' => 'نفسي اشوف',
        ]]);

        $first = $this->decide('نفسي اشوف بيت على البحر وقت الغروب');

        $this->assertSame('model', $first->source);
        $this->assertSame(['image_generation'], $first->capabilities());

        // The exact message is active at once; the broader phrase waits for a second confirmation.
        $this->assertDatabaseHas('ai_learned_intents', ['phrase' => 'نفسي اشوف بيت علي البحر وقت الغروب', 'match_mode' => 'exact', 'is_active' => true]);
        $this->assertDatabaseHas('ai_learned_intents', ['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'is_active' => false, 'confirmations' => 1]);

        // Same message again: answered from what was learned - the mock allows ONE call only.
        $second = $this->decide('نفسي اشوف بيت على البحر وقت الغروب');

        $this->assertSame('learned', $second->source);
        $this->assertSame(['image_generation'], $second->capabilities());
        $this->assertSame(1, AiLearnedIntent::query()->where('match_mode', 'exact')->value('hits'));
    }

    public function test_a_phrase_becomes_part_of_the_dictionary_after_the_model_confirms_it_twice(): void
    {
        $answer = ['intents' => ['image_generation'], 'confidence' => 0.95, 'file_format' => null, 'trigger_phrase' => 'نفسي اشوف'];

        // Two different messages, same phrase -> two model calls, then free.
        $this->fakeModel([$answer, $answer]);

        $this->decide('نفسي اشوف بيت على البحر وقت الغروب');
        $this->decide('نفسي اشوف قطه بتلعب في الجنينه');

        $this->assertDatabaseHas('ai_learned_intents', ['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'is_active' => true, 'confirmations' => 2]);

        // A brand-new message with the phrase: no third model call (mock allows exactly two).
        $third = $this->decide('نفسي اشوف سفينه فضاء عملاقه فوق القمر');

        $this->assertSame('learned', $third->source);
        $this->assertSame(['image_generation'], $third->capabilities());
    }

    public function test_learned_edit_without_an_image_falls_back_to_a_new_image(): void
    {
        AiLearnedIntent::query()->create([
            'phrase' => 'غير لي المنظر', 'match_mode' => 'phrase', 'intent' => 'image_edit', 'language' => 'ar',
            'confidence' => 0.95, 'confirmations' => 2, 'is_active' => true,
        ]);
        $this->neverAskModel();

        $this->assertSame(['image_generation'], $this->decide('غير لي المنظر بتاع القصر')->capabilities());
        $this->assertTrue($this->decide('غير لي المنظر بتاع القصر', true, false, true)->wantsImageEdit());
    }

    public function test_a_learned_voice_phrase_still_respects_an_explicit_no_voice(): void
    {
        AiLearnedIntent::query()->create([
            'phrase' => 'كلمني يا صاحبي', 'match_mode' => 'phrase', 'intent' => 'voice_reply', 'language' => 'ar',
            'confidence' => 0.95, 'confirmations' => 2, 'is_active' => true,
        ]);
        // Exactly one model call is allowed: the "بدون صوت" message below
        // (the learned phrase must NOT fire there, so it falls through).
        $this->fakeModel([['intents' => ['chat'], 'confidence' => 0.9, 'file_format' => null, 'trigger_phrase' => null]]);

        $this->assertTrue($this->decide('كلمني يا صاحبي في الموضوع ده')->wantsVoiceReply());
        $this->assertTrue($this->decide('كلمني يا صاحبي بس بدون صوت')->isEmpty());
    }

    public function test_file_output_keeps_the_format_the_model_chose(): void
    {
        $this->fakeModel([[
            'intents' => ['file_output'], 'confidence' => 0.92, 'file_format' => 'xlsx', 'trigger_phrase' => 'جهزهالي كشيت',
        ]]);

        $decision = $this->decide('جهزهالي كشيت بالمصاريف الشهريه');

        $this->assertTrue($decision->wantsFileOutput());
        $this->assertSame('xlsx', $decision->fileFormat);
    }

    public function test_low_confidence_is_treated_as_chat_remembered_briefly_and_not_learned(): void
    {
        $this->fakeModel([['intents' => ['web_search'], 'confidence' => 0.4, 'file_format' => null, 'trigger_phrase' => 'ايه الاخبار']]);

        $this->assertTrue($this->decide('حاجه غريبه مش واضح قصدها خالص')->isEmpty());
        $this->assertSame(0, AiLearnedIntent::query()->count());

        // Same message again: negative cache -> no second model call (mock allows one).
        $this->assertTrue($this->decide('حاجه غريبه مش واضح قصدها خالص')->isEmpty());
    }

    public function test_a_chat_answer_is_chat(): void
    {
        $this->fakeModel([['intents' => ['chat'], 'confidence' => 0.99, 'file_format' => null, 'trigger_phrase' => null]]);

        $this->assertTrue($this->decide('ايه رايك في فريق الاهلي السنه دي')->isEmpty());
        $this->assertSame(0, AiLearnedIntent::query()->count());
    }

    public function test_a_model_that_disagrees_with_itself_deactivates_the_phrase(): void
    {
        $this->fakeModel([
            ['intents' => ['image_generation'], 'confidence' => 0.95, 'trigger_phrase' => 'وريني منظر'],
            ['intents' => ['web_search'], 'confidence' => 0.95, 'trigger_phrase' => 'وريني منظر'],
            ['intents' => ['image_generation'], 'confidence' => 0.95, 'trigger_phrase' => 'وريني منظر'],
        ]);

        $this->decide('وريني منظر بيت على البحر');
        $this->decide('وريني منظر الجو بكره يعني');
        $this->decide('وريني منظر قصر في الصحراء');

        $row = AiLearnedIntent::query()->where('phrase', 'وريني منظر')->where('match_mode', 'phrase')->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->is_active, 'contradicted phrase never becomes active on its own');
        $this->assertGreaterThan(0, $row->conflicts);
    }

    public function test_a_phrase_an_admin_disabled_is_not_switched_back_on(): void
    {
        AiLearnedIntent::query()->create([
            'phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar',
            'confidence' => 0.95, 'confirmations' => 1, 'conflicts' => 1, 'is_active' => false,
        ]);
        $answer = ['intents' => ['image_generation'], 'confidence' => 0.95, 'trigger_phrase' => 'نفسي اشوف'];
        $this->fakeModel([$answer, $answer]);

        $this->decide('نفسي اشوف بيت على البحر وقت الغروب');
        $this->decide('نفسي اشوف قطه بتلعب في الجنينه');

        $this->assertFalse(AiLearnedIntent::query()->where('phrase', 'نفسي اشوف')->value('is_active'));
    }

    public function test_repeated_provider_failures_stop_the_router_asking_and_chat_is_unaffected(): void
    {
        config(['ai.intent_router.failure_threshold' => 2]);

        // Only TWO failing calls are allowed; the third message must not reach the model.
        $this->fakeModel([null, null]);

        foreach (['رسالة غريبة رقم واحد بدون معنى', 'رسالة غريبة رقم اتنين بدون معنى', 'رسالة غريبة رقم تلاته بدون معنى'] as $message) {
            $this->assertTrue($this->decide($message)->isEmpty());
        }
    }

    public function test_garbage_from_the_model_is_ignored(): void
    {
        $this->fakeModel(['I am not JSON at all']);

        $this->assertTrue($this->decide('رسالة غريبة بدون معنى واضح خالص')->isEmpty());
        $this->assertSame(0, AiLearnedIntent::query()->count());
    }

    public function test_personal_details_are_never_stored(): void
    {
        $this->fakeModel([['intents' => ['image_generation'], 'confidence' => 0.97, 'trigger_phrase' => 'نفسي اشوف']]);

        $this->decide('نفسي اشوف بيت رقم تليفوني 01012345678');

        $this->assertDatabaseMissing('ai_learned_intents', ['match_mode' => 'exact']);
    }
}
