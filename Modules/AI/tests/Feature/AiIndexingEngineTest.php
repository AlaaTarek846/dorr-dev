<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\Chunking\AiChunkingEngine;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\AI\Services\Indexing\AiIndexingEngine;
use Modules\User\Models\User;
use Tests\TestCase;

class AiIndexingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function makeIndexedFile(): array
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = User::query()->create([
            'name' => 'Indexing Test Owner',
            'email' => 'indexing-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/notes.md', "# Notes\n\nSome content for indexing tests.");

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'notes.md',
            'file_path' => 'ai-chat/test/notes.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => 40,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $chunks = app(AiChunkingEngine::class)->chunk($file->refresh());

        return [$file, $chunks];
    }

    public function test_index_marks_chunks_indexed_with_timestamp(): void
    {
        [$file, $chunks] = $this->makeIndexedFile();

        app(AiIndexingEngine::class)->index($chunks);

        foreach (AiFileChunk::query()->where('file_id', $file->id)->get() as $chunk) {
            $this->assertSame(AiFileChunk::STATUS_INDEXED, $chunk->status);
            $this->assertNotNull($chunk->indexed_at);
        }
    }

    public function test_auto_embed_defaults_to_false_so_no_embedding_api_call_is_attempted(): void
    {
        $this->assertFalse((bool) config('ai.indexing.auto_embed'));
    }

    public function test_remove_deletes_the_files_content_refs_and_marks_chunks_deleted(): void
    {
        [$file, $chunks] = $this->makeIndexedFile();
        $disk = (string) config('ai.chunking.storage_disk', 'public');

        app(AiIndexingEngine::class)->index($chunks);
        $firstRef = $chunks[0]->content_ref;
        $this->assertTrue(Storage::disk($disk)->exists($firstRef));

        app(AiIndexingEngine::class)->remove($file);

        $this->assertFalse(Storage::disk($disk)->exists($firstRef));

        foreach (AiFileChunk::query()->where('file_id', $file->id)->get() as $chunk) {
            $this->assertSame(AiFileChunk::STATUS_DELETED, $chunk->status);
            $this->assertFalse((bool) $chunk->is_active);
        }
    }

    public function test_indexing_empty_chunk_list_is_a_safe_noop(): void
    {
        // Must not throw / must not touch file_id=null rows.
        app(AiIndexingEngine::class)->index([]);
        $this->assertTrue(true);
    }
}
