<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Safety\RiskAssessment;
use Modules\AI\Safety\RiskClassifier;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * A real, full end-to-end exercise of AiChatService::sendMessage() for
 * an attached image - not the isolated/reflection-based tests the rest
 * of this suite uses for internal steps. Written specifically because
 * every earlier fix in this area (AiRequiredCapabilityResolver,
 * AiProvider::defaultRegisteredModel(), the honesty system messages)
 * was verified piece by piece, never through the actual wiring a real
 * HTTP request exercises - and the user kept hitting "I can't see the
 * image" live even after each fix and after manually re-tagging the
 * model's capabilities in the admin UI. This proves, at the full
 * sendMessage() boundary with a realistic fixture (a real registered
 * vision-capable model, is_default true, active), that an attached
 * image's actual bytes reach AiGateway::chat() and the reply is not the
 * "I can't see it" honesty fallback - or fails loudly showing exactly
 * where the real chain breaks, instead of another guess.
 */
class AiChatSendMessageImageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This test pins the exact AiGateway::chat() calls of one turn, so the DORR AI safety
        // classifier keeps to its keyword rules here instead of making its own model call
        // (that path is covered by tests/Feature/AiSafetyTest).
        $this->app->instance(RiskClassifier::class, new class($this->app->make(AiGateway::class)) extends RiskClassifier
        {
            public function classify(string $request, ?AiProvider $provider = null): RiskAssessment
            {
                return $this->byRules($request);
            }
        });
    }

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Vision Integration Owner',
            'email' => 'vision-integration-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Vision integration test conversation',
        ]);
    }

    /**
     * AiChatUsageGuard::evaluate() 402s with reason "no_plan" for a
     * fresh owner unless a real is_trial+is_active AiPlan row exists to
     * auto-provision their AiSubscription from - a real, observed test
     * gap (this is the first test in the whole suite to ever call
     * sendMessage() end-to-end; every other test deliberately bypasses
     * it via reflection, so this fixture requirement was never hit
     * before).
     */
    protected function makeTrialPlan(): AiPlan
    {
        return AiPlan::query()->create([
            'name' => 'Trial Plan (voice/vision integration test)',
            'code' => 'trial-integration-test-'.uniqid(),
            'is_trial' => true,
            'is_active' => true,
            'sort_order' => 1,
            'usage_minutes' => 60,
            'cooldown_minutes' => 5,
        ]);
    }

    public function test_a_plain_explain_the_image_request_actually_sends_the_image_bytes_to_the_provider(): void
    {
        Config::set('ai.chat.verification.enabled', false);

        $this->makeTrialPlan();

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);
        $repository->setDefault('openai');

        // Exactly the state the user confirmed after following every
        // earlier instruction: a real, active, is_default model tagged
        // with vision.
        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $capturedMessages = null;

        $this->mock(AiGateway::class, function ($mock) use (&$capturedMessages) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($provider, $messages) use (&$capturedMessages) {
                    $capturedMessages = $messages;

                    return true;
                })
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => 'اللوجو ده شعار باللون الأزرق مكتوب عليه DORR.',
                ]);
        });

        $attachment = UploadedFile::fake()->image('logo (3).png', 20, 20);

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, 'حلل الصوره', $attachment);

        $payload = $response->getData(true);

        $this->assertTrue($payload['success'], 'sendMessage() did not even succeed: '.json_encode($payload));

        $replyContent = $payload['data']['assistant_message']['content'] ?? null;

        // The decisive assertion: the real answer came back, not the "I
        // can't see this image" honesty fallback text.
        $this->assertSame('اللوجو ده شعار باللون الأزرق مكتوب عليه DORR.', $replyContent);

        $this->assertNotNull($capturedMessages, 'AiGateway::chat() was never called at all.');

        $lastMessage = end($capturedMessages);

        $this->assertIsArray($lastMessage['content'] ?? null,
            'The current user turn was sent as a plain string, meaning buildImagePayload() returned null and the image was never actually attached to the outgoing request.');
        $this->assertArrayHasKey('image', $lastMessage['content']);
        $this->assertNotEmpty($lastMessage['content']['image']['base64'] ?? null);
    }
}
