<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileCitation;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 9 (doc S24/S36): resolveFileRetrievalContext() is the chat-
 * pipeline integration point, wired into AiChatService::sendMessage()
 * right alongside the existing admin-Knowledge-Base retrieval call
 * (doc S24: "prefer a clean extension point... existing normal chat
 * must continue working exactly as before"). It is protected, and the
 * full sendMessage() pipeline has far too many unrelated dependencies
 * (provider dispatch, safety checks, capability resolution) to exercise
 * cheaply here, so - exactly as doc S36 anticipates for a method like
 * this - it is called directly via reflection against a real database
 * and real indexed chunks, never a hand-built fake AiBuiltContext.
 */
class AiChatServiceFileRetrievalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Chat Retrieval Test Owner',
            'email' => 'chat-retrieval-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeIndexedFile(User $owner, AiConversation $conversation, string $content): AiFile
    {
        $path = 'ai-chat/test/'.uniqid().'.md';
        Storage::disk('public')->put($path, $content);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'conversation_id' => $conversation->id,
            'file_name' => 'policy.md',
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $chunks = app(AiChunkingEngine::class)->chunk($file);
        app(AiIndexingEngine::class)->index($chunks);

        return $file;
    }

    protected function callResolveFileRetrievalContext(AiChatService $service, ...$args)
    {
        $method = new \ReflectionMethod($service, 'resolveFileRetrievalContext');
        $method->setAccessible(true);

        return $method->invoke($service, ...$args);
    }

    public function test_a_file_reference_question_retrieves_context_and_stores_citations(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Retrieval integration test',
        ]);

        $this->makeIndexedFile($owner, $conversation, "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $service = app(AiChatService::class);
        $context = $this->callResolveFileRetrievalContext(
            $service,
            $owner,
            $conversation,
            'Summarize the attached file for me.',
            false,
            $aiRequest,
        );

        $this->assertFalse($context->isEmpty());
        $this->assertNotNull($context->text);
        $this->assertStringContainsString('21 days', $context->text);

        $citations = AiFileCitation::query()->where('request_id', $aiRequest->id)->get();
        $this->assertNotEmpty($citations, 'citations must be persisted for an AiRequest whose chat turn used file retrieval context.');
        $this->assertStringContainsString('21 days', $citations->first()->excerpt);
        $this->assertNotNull($citations->first()->file_id);
        $this->assertNotNull($citations->first()->chunk_id);
    }

    public function test_an_ordinary_greeting_never_triggers_retrieval_or_citations(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Retrieval integration test - greeting',
        ]);

        $this->makeIndexedFile($owner, $conversation, "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $service = app(AiChatService::class);
        $context = $this->callResolveFileRetrievalContext(
            $service,
            $owner,
            $conversation,
            'Hello, how are you?',
            false,
            $aiRequest,
        );

        $this->assertTrue($context->isEmpty(), 'a plain greeting must never trigger file retrieval even when ready files exist in the conversation.');
        $this->assertSame(0, AiFileCitation::query()->where('request_id', $aiRequest->id)->count());
    }

    public function test_no_files_in_the_conversation_skips_retrieval_cheaply(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Retrieval integration test - no files',
        ]);

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $service = app(AiChatService::class);
        $context = $this->callResolveFileRetrievalContext(
            $service,
            $owner,
            $conversation,
            'Summarize the attached file for me.',
            false,
            $aiRequest,
        );

        $this->assertTrue($context->isEmpty());
        $this->assertSame(0, AiFileCitation::query()->where('request_id', $aiRequest->id)->count());
    }

    public function test_a_fresh_attachment_always_triggers_retrieval_even_without_file_keywords(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Retrieval integration test - fresh attachment',
        ]);

        $this->makeIndexedFile($owner, $conversation, "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $service = app(AiChatService::class);
        $context = $this->callResolveFileRetrievalContext(
            $service,
            $owner,
            $conversation,
            'What does this say?',
            true,
            $aiRequest,
        );

        $this->assertFalse($context->isEmpty());
    }
}
