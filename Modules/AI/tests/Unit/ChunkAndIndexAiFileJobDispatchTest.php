<?php

namespace Modules\AI\Tests\Unit;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ChunkAndIndexAiFileJob;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 8: ProcessAiFileJob dispatches ChunkAndIndexAiFileJob after a
 * successful processing step, gated by `ai.chunking.enabled`.
 */
class ChunkAndIndexAiFileJobDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_processing_dispatches_chunk_and_index_job(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['ai.chunking.enabled' => true]);

        $owner = User::query()->create([
            'name' => 'Dispatch Test Owner',
            'email' => 'dispatch-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/dispatch.md', "# T\n\nBody.");

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'dispatch.md',
            'file_path' => 'ai-chat/test/dispatch.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        Queue::assertPushed(ChunkAndIndexAiFileJob::class, fn ($job) => $job->file->id === $file->id);
    }

    public function test_chunking_disabled_via_config_skips_the_dispatch(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['ai.chunking.enabled' => false]);

        $owner = User::query()->create([
            'name' => 'Dispatch Test Owner 2',
            'email' => 'dispatch-test-2-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/dispatch2.md', "# T\n\nBody.");

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'dispatch2.md',
            'file_path' => 'ai-chat/test/dispatch2.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        Queue::assertNotPushed(ChunkAndIndexAiFileJob::class);
    }
}
