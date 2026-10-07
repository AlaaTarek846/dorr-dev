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
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * End-to-end: real file -> ProcessAiFileJob (Phase 1) -> AiChunkingEngine
 * (Phase 8), against the REAL normalized content AiFileEngine::getContext()
 * returns - not a hand-rolled fake shape - so these tests also validate
 * that Phase 8 integrates with Phase 1-7's real output.
 */
class AiChunkingEngineTest extends TestCase
{
    use RefreshDatabase;

    /** Storage::fake() wipes the disk, so it must run once per test, never once per file. */
    protected bool $storageFaked = false;

    protected function makeReadyMarkdownFile(string $content = "# Policy\n\nEmployees get 21 days of annual leave.\n\n## Details\n\nMore text here about the leave policy and how it works in practice."): AiFile
    {
        if (! $this->storageFaked) {
            Storage::fake('public');
            Storage::fake('local');
            $this->storageFaked = true;
        }

        $owner = User::query()->create([
            'name' => 'Chunking Test Owner',
            'email' => 'chunking-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        Storage::disk('public')->put('ai-chat/test/policy.md', $content);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'policy.md',
            'file_path' => 'ai-chat/test/policy.md',
            'disk' => 'public',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        return $file->refresh();
    }

    public function test_chunk_persists_rows_with_real_content_on_disk(): void
    {
        $file = $this->makeReadyMarkdownFile();

        $chunks = app(AiChunkingEngine::class)->chunk($file);

        $this->assertNotEmpty($chunks);

        foreach ($chunks as $chunk) {
            $this->assertSame($file->id, $chunk->file_id);
            $this->assertSame(1, $chunk->content_version);
            $this->assertTrue($chunk->is_active);
            $this->assertNotEmpty($chunk->checksum);
            $this->assertNotNull($chunk->readContent());
        }
    }

    public function test_chunking_the_same_unchanged_file_twice_never_creates_duplicate_active_chunks(): void
    {
        $file = $this->makeReadyMarkdownFile();
        $engine = app(AiChunkingEngine::class);

        $firstRun = $engine->chunk($file);
        $firstCount = AiFileChunk::query()->where('file_id', $file->id)->count();

        $secondRun = $engine->chunk($file);
        $secondCount = AiFileChunk::query()->where('file_id', $file->id)->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertSame(count($firstRun), count($secondRun));
        $this->assertSame(
            $firstRun[0]->chunk_key,
            AiFileChunk::query()->where('file_id', $file->id)->orderBy('chunk_index')->first()->chunk_key,
        );
    }

    public function test_rechunk_marks_previous_version_stale_and_inactive(): void
    {
        $file = $this->makeReadyMarkdownFile();
        $engine = app(AiChunkingEngine::class);

        $engine->chunk($file);
        $engine->rechunk($file->refresh());

        $v1 = AiFileChunk::query()->where('file_id', $file->id)->where('content_version', 1)->get();
        $v2 = AiFileChunk::query()->where('file_id', $file->id)->where('content_version', 2)->get();

        $this->assertNotEmpty($v1);
        $this->assertNotEmpty($v2);

        foreach ($v1 as $chunk) {
            $this->assertFalse($chunk->is_active);
            $this->assertSame('stale', $chunk->status);
        }

        foreach ($v2 as $chunk) {
            $this->assertTrue($chunk->is_active);
        }
    }

    public function test_only_the_latest_version_is_ever_considered_active_at_once(): void
    {
        $file = $this->makeReadyMarkdownFile();
        $engine = app(AiChunkingEngine::class);

        $engine->chunk($file);
        $engine->rechunk($file->refresh());
        $engine->rechunk($file->refresh());

        $activeVersions = AiFileChunk::query()
            ->where('file_id', $file->id)
            ->where('is_active', true)
            ->pluck('content_version')
            ->unique();

        $this->assertCount(1, $activeVersions);
        $this->assertSame(3, $activeVersions->first());
    }

    public function test_chunks_from_two_different_files_never_mix(): void
    {
        $fileA = $this->makeReadyMarkdownFile("# A\n\nContent about topic A.");
        $fileB = $this->makeReadyMarkdownFile("# B\n\nContent about topic B.");

        $engine = app(AiChunkingEngine::class);
        $engine->chunk($fileA);
        $engine->chunk($fileB);

        $chunksA = AiFileChunk::query()->where('file_id', $fileA->id)->get();
        $chunksB = AiFileChunk::query()->where('file_id', $fileB->id)->get();

        $this->assertTrue($chunksA->every(fn ($c) => $c->file_id === $fileA->id));
        $this->assertTrue($chunksB->every(fn ($c) => $c->file_id === $fileB->id));
    }

    public function test_chunking_an_image_file_produces_one_image_reference_chunk_not_an_empty_content_error(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = User::query()->create([
            'name' => 'Image Chunk Test Owner',
            'email' => 'image-chunk-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        $tempPath = sys_get_temp_dir().'/chunk-test-'.uniqid().'.png';
        $image = imagecreatetruecolor(20, 10);
        imagepng($image, $tempPath);
        imagedestroy($image);

        Storage::disk('public')->put('ai-chat/test/photo.png', file_get_contents($tempPath));
        unlink($tempPath);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'photo.png',
            'file_path' => 'ai-chat/test/photo.png',
            'disk' => 'public',
            'mime_type' => 'image/png',
            'file_size' => 200,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);

        // Regression guard: an image file has no text/blocks at all,
        // which an earlier version of AiChunkingEngine's "is this
        // empty?" check (run BEFORE resolving a strategy) wrongly
        // treated as CHUNKING_EMPTY_CONTENT for every image. The check
        // now runs AFTER strategy resolution so ImageReferenceChunker's
        // real metadata-only record still counts as content.
        $chunks = app(AiChunkingEngine::class)->chunk($file);

        $this->assertCount(1, $chunks);
        $this->assertSame('image_reference', $chunks[0]->content_type);
    }

    public function test_large_number_of_chunks_is_batch_inserted_without_losing_or_duplicating_rows(): void
    {
        config(['ai.chunking.batch_size' => 10]);
        config(['ai.chunking.document.max_characters' => 40]);
        config(['ai.chunking.document.min_characters' => 1]);

        // Enough distinct paragraphs to force several batches at
        // batch_size=10 - proves array_chunk()'d upsert() calls never
        // drop or duplicate a row across batch boundaries.
        $paragraphs = [];
        for ($i = 1; $i <= 45; $i++) {
            $paragraphs[] = "Paragraph number {$i} with some unique content to avoid collapsing.";
        }

        $content = "# Big Doc\n\n".implode("\n\n", $paragraphs);
        $file = $this->makeReadyMarkdownFile($content);

        $chunks = app(AiChunkingEngine::class)->chunk($file);

        $this->assertSame(count($chunks), AiFileChunk::query()->where('file_id', $file->id)->count());
        $this->assertSame(count($chunks), AiFileChunk::query()->where('file_id', $file->id)->distinct('chunk_key')->count('chunk_key'));
    }

    public function test_chunking_throws_too_large_when_text_exceeds_the_configured_cap(): void
    {
        config(['ai.chunking.max_file_characters' => 100]);

        $file = $this->makeReadyMarkdownFile(str_repeat('word ', 50));

        $this->expectException(\Modules\AI\Services\Chunking\AiChunkingException::class);

        try {
            app(AiChunkingEngine::class)->chunk($file);
        } catch (\Modules\AI\Services\Chunking\AiChunkingException $e) {
            $this->assertSame('CHUNKING_TOO_LARGE', $e->errorCode);

            throw $e;
        }
    }

    public function test_deleting_the_file_cascades_to_its_chunks(): void
    {
        $file = $this->makeReadyMarkdownFile();
        app(AiChunkingEngine::class)->chunk($file);

        $this->assertGreaterThan(0, AiFileChunk::query()->where('file_id', $file->id)->count());

        $file->forceDelete();

        $this->assertSame(0, AiFileChunk::query()->where('file_id', $file->id)->count());
    }
}
