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
use Modules\AI\Services\AiConversationFileScope;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 10 (doc S11/S21/S39): multi-file chat integration -
 * resolveFileRetrievalContext() (same reflection approach Phase 9's own
 * AiChatServiceFileRetrievalIntegrationTest already uses) exercised
 * with two real, indexed files attached to one conversation, covering
 * explicit file_ids precedence, FILE: grouping in the built context,
 * multi-file citations, and single-file regression.
 */
class AiChatServiceMultiFileIntegrationTest extends TestCase
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
            'name' => 'Multi-file Chat Test Owner',
            'email' => 'multi-file-chat-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeIndexedFile(User $owner, AiConversation $conversation, string $name, string $content): AiFile
    {
        $path = 'ai-chat/test/'.uniqid().'.md';
        Storage::disk('public')->put($path, $content);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'conversation_id' => $conversation->id,
            'file_name' => $name.'.md',
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

    public function test_a_comparison_question_retrieves_evidence_from_both_conversation_files(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Multi-file comparison test',
        ]);

        $contract = $this->makeIndexedFile($owner, $conversation, 'contract', "# Contract\n\nPayment terms require 30 days net.");
        $proposal = $this->makeIndexedFile($owner, $conversation, 'proposal', "# Proposal\n\nOur proposal's payment terms offer a 10% early discount.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $context = $this->callResolveFileRetrievalContext(
            app(AiChatService::class),
            $owner,
            $conversation,
            'Compare what these files say about payment terms.',
            false,
            $aiRequest,
        );

        $this->assertFalse($context->isEmpty());

        $citedFileIds = array_unique(array_map(fn (array $c) => $c['file_id'], $context->citations));
        $this->assertContains($contract->id, $citedFileIds);
        $this->assertContains($proposal->id, $citedFileIds);

        // doc S9: more than one distinct file in the result set gets a
        // "FILE: <name>" grouping header in the built context text.
        $this->assertStringContainsString('FILE:', $context->text);

        $citations = AiFileCitation::query()->where('request_id', $aiRequest->id)->get();
        $citedFileIdsPersisted = $citations->pluck('file_id')->unique()->all();
        $this->assertContains($contract->id, $citedFileIdsPersisted);
        $this->assertContains($proposal->id, $citedFileIdsPersisted);
    }

    public function test_explicit_file_ids_restrict_retrieval_to_only_those_files(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Explicit scope precedence test',
        ]);

        $contract = $this->makeIndexedFile($owner, $conversation, 'contract', "# Contract\n\nPayment terms require 30 days net.");
        $proposal = $this->makeIndexedFile($owner, $conversation, 'proposal', "# Proposal\n\nOur proposal's payment terms offer a 10% early discount.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $context = $this->callResolveFileRetrievalContext(
            app(AiChatService::class),
            $owner,
            $conversation,
            'What do the payment terms say?',
            false,
            $aiRequest,
            [$contract->id],
        );

        $this->assertFalse($context->isEmpty());

        $citedFileIds = array_unique(array_map(fn (array $c) => $c['file_id'], $context->citations));
        $this->assertSame([$contract->id], $citedFileIds, 'explicit file_ids must restrict retrieval, even though the proposal file is also attached to this conversation.');
    }

    public function test_single_file_conversation_gets_no_file_grouping_header(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Single-file regression test',
        ]);

        $this->makeIndexedFile($owner, $conversation, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $context = $this->callResolveFileRetrievalContext(
            app(AiChatService::class),
            $owner,
            $conversation,
            'Summarize the attached file for me.',
            false,
            $aiRequest,
        );

        $this->assertFalse($context->isEmpty());
        $this->assertStringNotContainsString('FILE:', $context->text, 'single-file retrieval must look exactly like Phase 9 - no grouping header noise.');
    }

    public function test_detached_file_is_excluded_from_retrieval(): void
    {
        $owner = $this->makeOwner();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Detach regression test',
        ]);

        $file = $this->makeIndexedFile($owner, $conversation, 'policy', "# Leave Policy\n\nEmployees get 21 days of annual leave per year.");
        app(AiConversationFileScope::class)->detach($conversation, $file);

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
        ]);

        $context = $this->callResolveFileRetrievalContext(
            app(AiChatService::class),
            $owner,
            $conversation,
            'Summarize the attached file for me.',
            false,
            $aiRequest,
        );

        $this->assertTrue($context->isEmpty(), 'a detached file must never be retrieved, even though the message still references "the attached file".');
    }
}
