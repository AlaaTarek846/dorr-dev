<?php

namespace Modules\AI\Tests\Unit;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\AiFileEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Doc S33/S34: AiFileEngine::getContext() is the clean read API the AI
 * Orchestrator will call later - proves it returns the real persisted
 * text/blocks/metadata for a ready file, and an honest, empty-but-not-
 * fake context for a file that is not ready yet.
 */
class AiFileEngineGetContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_normalized_content_for_a_ready_file(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = User::query()->create([
            'name' => 'Context Test Owner',
            'email' => 'context-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/notes.md', "# Title\n\nBody text.");

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'notes.md',
            'file_path' => 'ai-chat/test/notes.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => 20,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $context = app(AiFileEngine::class)->getContext($file);

        $this->assertSame(AiFile::STATUS_READY, $context['status']);
        $this->assertSame('markdown', $context['document_type']);
        $this->assertNotEmpty($context['blocks']);
        $this->assertStringContainsString('Title', (string) $context['text']);
    }

    public function test_returns_an_honest_empty_context_for_a_file_still_processing(): void
    {
        $owner = User::query()->create([
            'name' => 'Context Test Owner 2',
            'email' => 'context-test-2-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'still-going.pdf',
            'file_path' => 'ai-chat/test/still-going.pdf',
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'processing_status' => AiFile::STATUS_PROCESSING,
        ]);

        $context = app(AiFileEngine::class)->getContext($file);

        $this->assertSame(AiFile::STATUS_PROCESSING, $context['status']);
        $this->assertNull($context['text']);
        $this->assertSame([], $context['blocks']);
    }
}
