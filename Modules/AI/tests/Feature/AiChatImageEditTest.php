<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The user explicitly demanded real image editing after the honesty-only
 * fix (AiChatImageActionHonestyTest) correctly made the model admit it
 * couldn't deliver a file, but that was not what was asked for: "ياباشا
 * ده الشات انا عايزه يعدلى صوره ويبعتهالى" - this chat should actually
 * edit an image and send it back. This proves the real path end to end:
 *
 * 1. AiGateway::editImage() really calls OpenAI's /images/edits endpoint
 *    (multipart, with the source image attached) and correctly parses
 *    both a successful b64_json reply and a failed HTTP response.
 * 2. AiChatService::resolveSourceImageBytes() finds a source image from
 *    a freshly-attached file, falls back to the most recent image
 *    already in the conversation when nothing new was attached (the
 *    exact "لا غير انت لون الصورة" follow-up scenario from the user's
 *    transcript), and correctly reports "no source" when neither exists.
 * 3. AiChatService::tryHandleImageEdit() ties it together: it stores the
 *    returned image as a real ai_conversation_attachments row on the
 *    assistant's own message (the same mechanism a user upload uses, so
 *    the existing chat UI renders it with zero frontend changes) and
 *    returns a successful JSON response shaped like every other
 *    sendMessage() reply.
 *
 * Reached by reflection/direct service calls rather than the full
 * sendMessage() entrypoint, matching every other test in this suite
 * (AiChatIdempotencyTest, AiChatBroadcastTest, AiChatImageActionHonestyTest)
 * - sendMessage() itself needs a large, unrelated fixture (routing
 * policy, safety rules, trial control) that has nothing to do with
 * whether the image-edit call and storage actually work.
 */
class AiChatImageEditTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Image Edit Owner',
            'email' => 'image-edit-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Image edit test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function makeProvider(): AiProvider
    {
        $repository = app(AiProviderRepository::class);

        return $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function fakeSuccessfulEdit(): void
    {
        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('fake-png-bytes')],
                ],
            ], 200),
        ]);
    }

    protected function callResolveSourceImageBytes(AiConversation $conversation, ?UploadedFile $attachment, string $content = '', bool $recentImageExists = false): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'resolveSourceImageBytes');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $conversation, $attachment, $content, $recentImageExists);
    }

    protected function callTryHandleImageEdit(
        Admin $owner,
        AiConversation $conversation,
        AiMessage $userMessage,
        AiRequest $aiRequest,
        string $content,
        ?UploadedFile $attachment,
        array $candidate,
        bool $recentImageExists = false,
    ) {
        $method = new ReflectionMethod(AiChatService::class, 'tryHandleImageEdit');
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

        return $method->invoke(app(AiChatService::class), $owner, $conversation, $userMessage, $aiRequest, $content, $attachment, $candidate, $usage, $recentImageExists);
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

    protected function makeUserMessage(AiConversation $conversation): AiMessage
    {
        return $conversation->messages()->create([
            'sequence_number' => 1,
            'role' => AiMessage::ROLE_USER,
            'content' => 'غيرلي اللون للأخضر',
        ]);
    }

    // --- AiGateway::editImage() -------------------------------------------------

    public function test_gateway_edit_image_sends_a_real_multipart_request_and_parses_the_returned_image(): void
    {
        $provider = $this->makeProvider();
        $this->fakeSuccessfulEdit();

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->editImage($callProvider, 'raw-source-bytes', 'image/png', 'make it green');

        $this->assertTrue($result['success']);
        $this->assertSame('fake-png-bytes', base64_decode($result['image']['base64']));
        $this->assertSame('image/png', $result['image']['mime']);

        Http::assertSent(function ($request) {
            // Multipart requests carry their fields as a list of
            // ['name' => ..., 'contents' => ...] parts (that is what
            // hasFile() itself searches) - $request['model'] does not
            // work here the way it does for a JSON/form body, since
            // data() returns that raw multipart part list, not a flat
            // key => value map.
            $field = fn (string $name) => collect($request->data())
                ->firstWhere('name', $name)['contents'] ?? null;

            return $request->url() === 'https://api.openai.com/v1/images/edits'
                && $request->hasFile('image')
                && $field('model') === 'gpt-image-1'
                && $field('prompt') === 'make it green';
        });
    }

    public function test_gateway_edit_image_surfaces_a_failure_instead_of_pretending_to_succeed(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response([
                'error' => ['message' => 'content_policy_violation'],
            ], 400),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->editImage($callProvider, 'raw-source-bytes', 'image/png', 'make it green');

        $this->assertFalse($result['success']);
        $this->assertNull($result['image']);
    }

    /**
     * Real, observed bug: a genuine network-level failure ("Connection
     * refused" - the same class of local network/AV/DPI issue already
     * diagnosed earlier in this project for large image payloads) used
     * to fall through OpenAiConnector::editImage()'s single generic
     * catch(Throwable), surfacing as "ai.unexpected_error" - whose
     * Arabic/English text literally said "while testing the connection",
     * which is deeply confusing for an image-edit failure that has
     * nothing to do with testing a connection. This proves a
     * ConnectionException specifically now gets the accurate
     * "ai.connection_failed" message instead, matching how
     * AbstractHttpConnector::attempt() already treats the same exception
     * for the ordinary chat path.
     */
    public function test_gateway_edit_image_reports_a_real_network_failure_as_a_connection_error_not_a_generic_one(): void
    {
        $provider = $this->makeProvider();

        Http::fake(function () {
            throw new ConnectionException('Connection refused for URI https://api.openai.com/v1/images/edits');
        });

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'gpt-image-1');

        $result = app(AiGateway::class)->editImage($callProvider, 'raw-source-bytes', 'image/png', 'make it green');

        $this->assertFalse($result['success']);
        $this->assertNull($result['image']);
        $this->assertStringContainsString(__('ai.connection_failed', ['message' => '']), $result['message']);
    }

    // --- AiChatService::resolveSourceImageBytes() --------------------------------

    public function test_resolve_source_image_prefers_a_freshly_attached_image(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $attachment = UploadedFile::fake()->image('new-upload.png', 10, 10);

        [$bytes, $mime] = $this->callResolveSourceImageBytes($conversation, $attachment);

        $this->assertNotNull($bytes);
        $this->assertSame('image/png', $mime);
    }

    public function test_resolve_source_image_falls_back_to_the_latest_image_in_the_conversation(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $userMessage = $this->makeUserMessage($conversation);

        $path = 'ai-chat/admin/'.$owner->id.'/earlier-upload.png';
        Storage::disk('public')->put($path, 'earlier-real-bytes');

        AiConversationAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $userMessage->id,
            'file_name' => 'earlier-upload.png',
            'file_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => strlen('earlier-real-bytes'),
        ]);

        // Follow-up request with no new attachment - exactly the
        // "لا غير انت لون الصورة" scenario from the user's transcript.
        // Root-cause fix (business-closure round): the fallback to "the
        // latest image in the conversation" now requires the message to
        // actually read as an edit of an EXISTING picture (an edit verb
        // + a mention of "the picture"), never a bare absence of an
        // attachment - see looksLikeEditOfExistingImage().
        [$bytes, $mime] = $this->callResolveSourceImageBytes($conversation, null, 'لا غير انت لون الصورة', recentImageExists: true);

        $this->assertSame('earlier-real-bytes', $bytes);
        $this->assertSame('image/png', $mime);
    }

    /**
     * Real, observed bug this guards against: the user asked to CREATE a
     * brand new, unrelated image ("اصنع صوره فيها طفل صغير" - "make a
     * picture of a small child") in a conversation that happened to
     * contain an old, completely unrelated image from earlier. Before
     * this fix, resolveSourceImageBytes() silently treated "no
     * attachment" as license to reuse that old unrelated image as the
     * implicit edit target, sending a nonsensical "edit this old photo
     * into: a small child" request to the provider (which failed with a
     * raw capacity error that then leaked into the chat). A pure "create
     * something new" request must never resolve to an old, unrelated
     * source image - it must come back with no source at all, so the
     * caller falls through to the honest "I can't generate images from
     * scratch" reply instead.
     */
    public function test_resolve_source_image_does_not_reuse_an_old_unrelated_image_for_a_brand_new_generation_request(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $userMessage = $this->makeUserMessage($conversation);

        $path = 'ai-chat/admin/'.$owner->id.'/old-unrelated-photo.png';
        Storage::disk('public')->put($path, 'old-unrelated-bytes');

        AiConversationAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $userMessage->id,
            'file_name' => 'old-unrelated-photo.png',
            'file_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => strlen('old-unrelated-bytes'),
        ]);

        [$bytes, $mime] = $this->callResolveSourceImageBytes($conversation, null, 'اصنع صوره فيها طفل صغير', recentImageExists: true);

        $this->assertNull($bytes);
        $this->assertNull($mime);
    }

    public function test_resolve_source_image_returns_null_when_no_image_exists_anywhere(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        [$bytes, $mime] = $this->callResolveSourceImageBytes($conversation, null);

        $this->assertNull($bytes);
        $this->assertNull($mime);
    }

    // --- AiChatService::tryHandleImageEdit() (integration) -----------------------

    public function test_try_handle_image_edit_stores_the_returned_image_on_the_assistant_message_and_succeeds(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();
        $userMessage = $this->makeUserMessage($conversation);
        $aiRequest = $this->makeAiRequest($owner);

        $this->fakeSuccessfulEdit();

        $attachment = UploadedFile::fake()->image('source.png', 10, 10);

        $candidate = ['provider' => $provider, 'model_key' => 'gpt-image-1'];

        $response = $this->callTryHandleImageEdit(
            $owner, $conversation, $userMessage, $aiRequest, 'غيرلي اللون للأخضر', $attachment, $candidate,
        );

        $this->assertNotNull($response, 'expected a real response, not a fallback to the honesty text path');
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

        $storedAttachment = AiConversationAttachment::query()
            ->where('message_id', $assistantMessage->id)
            ->first();

        $this->assertNotNull($storedAttachment, 'expected a real ai_conversation_attachments row for the generated image');
        $this->assertTrue(Storage::disk('public')->exists($storedAttachment->file_path));
        $this->assertSame('fake-png-bytes', Storage::disk('public')->get($storedAttachment->file_path));

        $aiRequest->refresh();
        $this->assertSame(AiRequest::STATUS_COMPLETED, $aiRequest->status);
    }

    public function test_try_handle_image_edit_returns_null_when_no_source_image_can_be_found_so_the_honesty_path_still_applies(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();
        $userMessage = $this->makeUserMessage($conversation);
        $aiRequest = $this->makeAiRequest($owner);

        $candidate = ['provider' => $provider, 'model_key' => 'gpt-image-1'];

        $response = $this->callTryHandleImageEdit(
            $owner, $conversation, $userMessage, $aiRequest, 'غيرلي اللون للأخضر', null, $candidate,
        );

        $this->assertNull($response, 'with nothing to edit, the caller must fall through to the honest text reply');
    }

    public function test_try_handle_image_edit_records_a_failed_request_and_an_error_message_when_the_provider_call_fails(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();
        $userMessage = $this->makeUserMessage($conversation);
        $aiRequest = $this->makeAiRequest($owner);

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response([
                'error' => ['message' => 'content_policy_violation'],
            ], 400),
        ]);

        $attachment = UploadedFile::fake()->image('source.png', 10, 10);
        $candidate = ['provider' => $provider, 'model_key' => 'gpt-image-1'];

        $response = $this->callTryHandleImageEdit(
            $owner, $conversation, $userMessage, $aiRequest, 'غيرلي اللون للأخضر', $attachment, $candidate,
        );

        $this->assertNotNull($response);

        // Matches the same envelope convention the main text-chat path
        // (dispatchWithFallback) already uses: even a failed provider call
        // still returns a normal ApiResponse::success() envelope (200,
        // 'success' => true) so the chat UI renders a conversational
        // assistant bubble explaining the failure instead of a raw error
        // toast - the actual failure signal is is_error on that message
        // and STATUS_FAILED on the ai_request row, both asserted below.
        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);

        $aiRequest->refresh();
        $this->assertSame(AiRequest::STATUS_FAILED, $aiRequest->status);
        $this->assertNotNull($aiRequest->error_message);

        $assistantMessage = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->first();

        $this->assertTrue((bool) $assistantMessage->is_error);
        $this->assertDatabaseCount('ai_conversation_attachments', 0);

        // Root-cause fix (business-closure round): the raw provider/gateway
        // error text must never reach the user-visible chat message - only
        // the friendly, translated failure text. The raw text is still
        // preserved for admins on $aiRequest->error_message (asserted
        // above via assertNotNull).
        $this->assertSame(__('ai.image_edit_failed'), $assistantMessage->content);
        $this->assertStringNotContainsString('content_policy_violation', $assistantMessage->content);
    }
}
