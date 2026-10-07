<?php

namespace Modules\AI\Tests\Unit;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiFileProcessing;
use Modules\AI\Services\FileProcessors\AiFileProcessorManager;
use Modules\User\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves ProcessAiFileJob actually runs a real processor against the
 * stored file and lands ai_files/ai_file_processing in the right final
 * state - the piece AiFileEngineTest intentionally leaves to this test
 * (that one only asserts the job gets dispatched, via Bus::fake()).
 */
class ProcessAiFileJobTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Process Job Test Owner',
            'email' => 'process-job-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_handle_extracts_text_and_marks_the_file_ready(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/report.txt', 'A real extractable report body.');

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'report.txt',
            'file_path' => 'ai-chat/test/report.txt',
            'mime_type' => 'text/plain',
            'file_size' => 33,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);
        $this->assertNull($file->processing_error);

        $step = AiFileProcessing::query()->where('file_id', $file->id)->sole();
        $this->assertSame(AiFileProcessing::STATUS_COMPLETED, $step->status);
        $this->assertNotNull($step->extracted_content_ref);
        $this->assertTrue(Storage::disk('local')->exists($step->extracted_content_ref));
        $this->assertStringContainsString(
            'A real extractable report body.',
            Storage::disk('local')->get($step->extracted_content_ref),
        );

        // Phase 2: the normalized block structure is persisted as its
        // own content reference, same pattern as extracted_content_ref.
        $this->assertNotNull($step->blocks_ref);
        $this->assertTrue(Storage::disk('local')->exists($step->blocks_ref));
        $blocks = json_decode(Storage::disk('local')->get($step->blocks_ref), true);
        $this->assertNotEmpty($blocks);
        $this->assertSame('paragraph', $blocks[0]['type']);

        $this->assertSame('txt', $file->metadata['document_type']);
        $this->assertArrayHasKey('processing_time_ms', $file->metadata);
    }

    public function test_handle_rejects_a_type_with_no_real_processor(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/archive.zip', 'irrelevant bytes');

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'archive.zip',
            'file_path' => 'ai-chat/test/archive.zip',
            'mime_type' => 'application/zip',
            'file_size' => 17,
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));

        $file->refresh();

        $this->assertSame(AiFile::STATUS_FAILED, $file->processing_status);
        $this->assertSame('unsupported_file_type', $file->processing_error);

        $step = AiFileProcessing::query()->where('file_id', $file->id)->sole();
        $this->assertSame(AiFileProcessing::STATUS_FAILED, $step->status);
    }

    /**
     * Phase 5 (doc S8/S23): proves the generic preview_assets ->
     * on-disk-file-plus-path-reference handling this job gained for
     * RasterImageFileProcessor actually persists real files and rewrites
     * metadata to hold refs, not raw bytes - and that reprocessing the
     * same file (e.g. a queue retry) overwrites those refs in place
     * rather than accumulating duplicate preview files (doc S23:
     * idempotent retries).
     */
    public function test_handle_persists_image_preview_assets_as_disk_files_with_path_references(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is not loaded in this environment.');
        }

        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();

        $gdImage = imagecreatetruecolor(80, 40);
        imagefill($gdImage, 0, 0, imagecolorallocate($gdImage, 50, 60, 70));
        ob_start();
        imagejpeg($gdImage);
        $jpegBytes = ob_get_clean();
        imagedestroy($gdImage);

        Storage::disk('public')->put('ai-chat/test/photo.jpg', $jpegBytes);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'photo.jpg',
            'file_path' => 'ai-chat/test/photo.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($jpegBytes),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);
        $this->assertArrayHasKey('preview_assets', $file->metadata);

        $thumbPath = $file->metadata['preview_assets']['thumbnail']['path'];
        $previewPath = $file->metadata['preview_assets']['preview']['path'];

        // Raw bytes never leak into the persisted row - only a path ref,
        // same storage convention as extracted_content_ref/blocks_ref.
        $this->assertArrayNotHasKey('bytes', $file->metadata['preview_assets']['thumbnail']);
        $this->assertTrue(Storage::disk('local')->exists($thumbPath));
        $this->assertTrue(Storage::disk('local')->exists($previewPath));
        $this->assertNotEmpty(Storage::disk('local')->get($thumbPath));

        // Doc S23: reprocessing (e.g. a retry) overwrites the same
        // deterministic path rather than creating a second file.
        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $thumbPathAfterRetry = $file->metadata['preview_assets']['thumbnail']['path'];
        $this->assertSame($thumbPath, $thumbPathAfterRetry);
        $this->assertTrue(Storage::disk('local')->exists($thumbPathAfterRetry));
    }

    /**
     * Phase 6: proves the full job pipeline persists real ffprobe-
     * derived audio metadata (and the waveform, which - unlike Phase
     * 5's image previews - is small JSON-safe numeric data, not binary
     * bytes, so it needs no disk-ref handling in this job at all; it
     * rides straight through in ai_files.metadata like every other
     * processor's metadata).
     */
    public function test_handle_extracts_real_audio_metadata_and_marks_the_file_ready(): void
    {
        $ffmpegAvailable = (function () {
            try {
                $p = new Process(['ffmpeg', '-version']);
                $p->setTimeout(5);
                $p->run();

                return $p->isSuccessful();
            } catch (\Throwable) {
                return false;
            }
        })();

        if (! $ffmpegAvailable) {
            $this->markTestSkipped('ffmpeg is not reachable in this environment.');
        }

        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();

        $localPath = sys_get_temp_dir().'/ai-job-audio-test-'.uniqid().'.mp3';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-ar', '44100', '-ac', '2', $localPath, '-y']))
            ->setTimeout(15)->mustRun();

        Storage::disk('public')->put('ai-chat/test/voice.mp3', file_get_contents($localPath));
        @unlink($localPath);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'voice.mp3',
            'file_path' => 'ai-chat/test/voice.mp3',
            'disk' => 'public',
            'mime_type' => 'audio/mpeg',
            'file_size' => Storage::disk('public')->size('ai-chat/test/voice.mp3'),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);
        $this->assertSame('audio', $file->metadata['document_type']);
        $this->assertSame('mp3', $file->metadata['format']);
        $this->assertSame(2, $file->metadata['channels']);
        $this->assertArrayHasKey('waveform', $file->metadata);
        $this->assertArrayNotHasKey('preview_assets', $file->metadata);
    }

    /**
     * Phase 7: the video equivalent of the audio test above - proves
     * the full job (not just VideoFileProcessor in isolation) lands a
     * real MP4 as `ready` with real dimensions/duration AND a generated
     * poster persisted through ProcessAiFileJob's EXISTING
     * `persistPreviewAssets()` path - no job changes were needed for
     * this (see VideoFileProcessor's own docblock), so this test is
     * also the proof that claim is actually true, not just asserted in
     * a docblock.
     */
    public function test_handle_extracts_real_video_metadata_and_persists_a_poster(): void
    {
        $ffmpegAvailable = (function () {
            try {
                $p = new Process(['ffmpeg', '-version']);
                $p->setTimeout(5);
                $p->run();

                return $p->isSuccessful();
            } catch (\Throwable) {
                return false;
            }
        })();

        if (! $ffmpegAvailable) {
            $this->markTestSkipped('ffmpeg is not reachable in this environment.');
        }

        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();

        $localPath = sys_get_temp_dir().'/ai-job-video-test-'.uniqid().'.mp4';
        (new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15:duration=2',
            '-c:v', 'libx264', '-an', $localPath, '-y',
        ]))->setTimeout(20)->mustRun();

        Storage::disk('public')->put('ai-chat/test/clip.mp4', file_get_contents($localPath));
        @unlink($localPath);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'clip.mp4',
            'file_path' => 'ai-chat/test/clip.mp4',
            'disk' => 'public',
            'mime_type' => 'video/mp4',
            'file_size' => Storage::disk('public')->size('ai-chat/test/clip.mp4'),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);
        $this->assertSame('video', $file->metadata['document_type']);
        $this->assertSame(320, $file->metadata['width']);
        $this->assertSame(240, $file->metadata['height']);
        $this->assertArrayHasKey('preview_assets', $file->metadata);
        $posterMeta = $file->metadata['preview_assets']['poster'];
        $this->assertSame('local', $posterMeta['disk']);
        $this->assertTrue(Storage::disk('local')->exists($posterMeta['path']));
    }

    /**
     * Phase 7 regression (doc S29/architectural-rule-43-style pin, same
     * as Phase 6's audio-routing fix): proves the webm/audio-video
     * routing fix actually works end-to-end through the real manager
     * the job uses - an audio-only WebM upload still correctly lands as
     * `document_type: audio`, not `video`, and not a processing
     * failure, even though VideoFileProcessor (not AudioFileProcessor)
     * is now the one the manager resolves `video/webm` to.
     */
    public function test_handle_still_classifies_an_audio_only_webm_as_audio_after_the_routing_change(): void
    {
        $ffmpegAvailable = (function () {
            try {
                $p = new Process(['ffmpeg', '-version']);
                $p->setTimeout(5);
                $p->run();

                return $p->isSuccessful();
            } catch (\Throwable) {
                return false;
            }
        })();

        if (! $ffmpegAvailable) {
            $this->markTestSkipped('ffmpeg is not reachable in this environment.');
        }

        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();

        $localPath = sys_get_temp_dir().'/ai-job-webm-audio-test-'.uniqid().'.webm';
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'libopus', $localPath, '-y']))
            ->setTimeout(15)->mustRun();

        Storage::disk('public')->put('ai-chat/test/voice.webm', file_get_contents($localPath));
        @unlink($localPath);

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'voice.webm',
            'file_path' => 'ai-chat/test/voice.webm',
            'disk' => 'public',
            'mime_type' => 'video/webm',
            'file_size' => Storage::disk('public')->size('ai-chat/test/voice.webm'),
            'processing_status' => AiFile::STATUS_UPLOADED,
        ]);

        (new ProcessAiFileJob($file))->handle(app(AiFileProcessorManager::class));
        $file->refresh();

        $this->assertSame(AiFile::STATUS_READY, $file->processing_status);
        $this->assertSame('audio', $file->metadata['document_type']);
    }
}
