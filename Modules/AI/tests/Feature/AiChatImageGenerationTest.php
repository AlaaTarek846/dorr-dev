<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiRequest;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Closes the third and final leg of the image business (alongside
 * AiChatImageEditTest for editing and AiChatVisionHonestyTest for
 * explaining an attached image): "اعمل صورة لمدينة مستقبلية في مصر" - a
 * brand new image from a text description alone, no source image
 * involved. Real, observed gap this fix closes: before it, ANY
 * image_generation-tagged request with no attachment - including a pure
 * "create something new" request - fell straight to
 * tryHandleImageEdit()'s "no source found" honesty path, or worse,
 * silently reused whatever unrelated image happened to be most recent in
 * the conversation as an implicit edit target. Now a genuine create-new
 * request (no attachment, no reference to an existing picture) goes
 * through AiGateway::generateImage() -> OpenAI's real /images/generations
 * endpoint, exactly mirroring the already-proven editImage() path.
 */
class AiChatImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Image Generation Owner',
            'email' => 'image-gen-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Image generation test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function makeProvider(): \Modules\AI\Models\AiProvider
    {
        $repository = app(AiProviderRepository::class);

        return $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function fakeSuccessfulGeneration(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('fake-generated-png-bytes')],
                ],
            ], 200),
        ]);
    }

    protected function makeAiRequest(Admin $owner): AiRequest
    {
        return AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
            'correlation_id' => 'trace-'.uniqid(),
        ]);
    }

    protected function makeUserMessage(AiConversation $conversation, string $content): AiMessage
    {
        return $conversation->messages()->create([
            'sequence_number' => 1,
            'role' => AiMessage::ROLE_USER,
            'content' => $content,
        ]);
    }

    protected function callTryHandleImageGeneration(
        Admin $owner,
        AiConversation $conversation,
        AiMessage $userMessage,
        AiRequest $aiRequest,
        string $content,
        array $candidate,
    ) {
        $method = new ReflectionMethod(AiChatService::class, 'tryHandleImageGeneration');
        $method->setAccessible(true);

        $usage = [
            'allowed' => true,
            'reason' => null,
            'plan' => null,
            'trial_status' => null,
            'remaining_seconds' => null,
            'cooldown_seconds_left' => null,
            'session' => null,
        ];

        return $method->invoke(app(AiChatService::class), $owner, $conversation, $userMessage, $aiRequest, $content, $candidate, $usage);
    }

    // --- AiGateway::generateImage() -----------------------------------------------

    public function test_gateway_generate_image_sends_a_real_json_request_and_parses_the_returned_image(): void
    {
        $provider = $this->makeProvider();
        $this->fakeSuccessfulGeneration();

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->generateImage($callProvider, 'a futuristic city in Egypt');

        $this->assertTrue($result['success']);
        $this->assertSame('fake-generated-png-bytes', base64_decode($result['image']['base64']));
        $this->assertSame('image/png', $result['image']['mime']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/images/generations'
                && $request['model'] === 'gpt-image-1'
                && $request['prompt'] === 'a futuristic city in Egypt';
        });
    }

    public function test_gateway_generate_image_surfaces_a_failure_instead_of_pretending_to_succeed(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'No available capacity was found for the model'],
            ], 429),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->generateImage($callProvider, 'a futuristic city in Egypt');

        $this->assertFalse($result['success']);
        $this->assertNull($result['image']);
    }

    public function test_gateway_generate_image_reports_a_real_network_failure_as_a_connection_error_not_a_generic_one(): void
    {
        $provider = $this->makeProvider();

        Http::fake(function () {
            throw new ConnectionException('Connection refused for URI https://api.openai.com/v1/images/generations');
        });

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->generateImage($callProvider, 'a futuristic city in Egypt');

        $this->assertFalse($result['success']);
        $this->assertNull($result['image']);
        $this->assertStringContainsString(__('ai.connection_failed', ['message' => '']), $result['message']);
    }

    // --- AiChatService::tryHandleImageGeneration() (integration) -------------------

    public function test_try_handle_image_generation_stores_the_returned_image_on_the_assistant_message_and_succeeds(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();
        $content = 'اعمل صورة لمدينة مستقبلية في مصر';
        $userMessage = $this->makeUserMessage($conversation, $content);
        $aiRequest = $this->makeAiRequest($owner);

        $this->fakeSuccessfulGeneration();

        $candidate = ['provider' => $provider, 'model_key' => 'gpt-image-1'];

        $response = $this->callTryHandleImageGeneration($owner, $conversation, $userMessage, $aiRequest, $content, $candidate);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['data']['assistant_message']['attachments']);

        $assistantMessage = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->first();

        $this->assertNotNull($assistantMessage);
        $this->assertFalse((bool) $assistantMessage->is_error);
        $this->assertSame(__('ai.image_generation_success'), $assistantMessage->content);

        $storedAttachment = AiConversationAttachment::query()
            ->where('message_id', $assistantMessage->id)
            ->first();

        $this->assertNotNull($storedAttachment, 'expected a real ai_conversation_attachments row for the generated image');
        $this->assertTrue(Storage::disk('public')->exists($storedAttachment->file_path));
        $this->assertSame('fake-generated-png-bytes', Storage::disk('public')->get($storedAttachment->file_path));

        $aiRequest->refresh();
        $this->assertSame(AiRequest::STATUS_COMPLETED, $aiRequest->status);
    }

    public function test_try_handle_image_generation_never_leaks_the_raw_provider_error_and_records_a_failed_request(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();
        $content = 'اصنع صوره فيها طفل صغير';
        $userMessage = $this->makeUserMessage($conversation, $content);
        $aiRequest = $this->makeAiRequest($owner);

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'No available capacity was found for the model'],
            ], 429),
        ]);

        $candidate = ['provider' => $provider, 'model_key' => 'gpt-image-2.5-flare'];

        $response = $this->callTryHandleImageGeneration($owner, $conversation, $userMessage, $aiRequest, $content, $candidate);

        $this->assertNotNull($response);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success']); // envelope success, not generation success - see AiChatImageEditTest for the same convention

        $aiRequest->refresh();
        $this->assertSame(AiRequest::STATUS_FAILED, $aiRequest->status);
        $this->assertNotNull($aiRequest->error_message);

        $assistantMessage = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->first();

        $this->assertTrue((bool) $assistantMessage->is_error);
        $this->assertSame(__('ai.image_generation_failed'), $assistantMessage->content);
        $this->assertStringNotContainsString('No available capacity', $assistantMessage->content);
        $this->assertDatabaseCount('ai_conversation_attachments', 0);
    }
}
