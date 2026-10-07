<?php

namespace Modules\AI\Tests\Unit;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Exceptions\AiFileException;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\AiFileEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 12 (doc S7/S28): the per-owner file-count/storage-bytes cap
 * added to AiFileEngine::storeUploadedFile() - abuse protection
 * independent of the per-request size/type checks AiFileEngineTest
 * already covers. Each test overrides the relevant config value
 * directly rather than relying on .env, so the test is self-contained
 * and does not depend on whatever this environment's real limits are.
 */
class AiFileOwnerQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'File Quota Test Owner',
            'email' => 'file-quota-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_upload_is_rejected_once_the_owner_is_at_the_file_count_cap(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['ai.files.max_files_per_owner' => 1, 'ai.files.max_storage_bytes_per_owner' => 0]);

        $owner = $this->makeOwner();

        // Pre-existing row for this owner, simulating one file already
        // stored (bypasses the full process() pipeline - only the
        // owner/file_size totals this check reads need to be real).
        AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'existing.txt',
            'stored_name' => 'existing.txt',
            'file_path' => 'ai-files/existing.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_READY,
        ]);

        $uploaded = UploadedFile::fake()->create('second.txt', 1, 'text/plain');

        $this->expectException(AiFileException::class);

        try {
            app(AiFileEngine::class)->storeUploadedFile($owner, $uploaded);
        } finally {
            Bus::assertNotDispatched(ProcessAiFileJob::class);
        }
    }

    public function test_upload_is_rejected_once_the_owner_is_at_the_storage_bytes_cap(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['ai.files.max_files_per_owner' => 0, 'ai.files.max_storage_bytes_per_owner' => 100]);

        $owner = $this->makeOwner();

        AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'big.txt',
            'stored_name' => 'big.txt',
            'file_path' => 'ai-files/big.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'file_size' => 95,
            'processing_status' => AiFile::STATUS_READY,
        ]);

        // 95 already used + a 10-byte upload = 105 > the 100-byte cap.
        $uploaded = UploadedFile::fake()->create('small.txt', 1, 'text/plain');

        $this->expectException(AiFileException::class);
        app(AiFileEngine::class)->storeUploadedFile($owner, $uploaded);
    }

    public function test_upload_is_allowed_when_both_caps_are_disabled(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['ai.files.max_files_per_owner' => 0, 'ai.files.max_storage_bytes_per_owner' => 0]);

        $owner = $this->makeOwner();
        $uploaded = UploadedFile::fake()->create('note.txt', 1, 'text/plain');

        $file = app(AiFileEngine::class)->storeUploadedFile($owner, $uploaded);

        $this->assertNotNull($file->id);
    }

    public function test_quota_counts_toward_the_calling_owner_only(): void
    {
        Storage::fake('public');
        Bus::fake();
        config(['ai.files.max_files_per_owner' => 1, 'ai.files.max_storage_bytes_per_owner' => 0]);

        $ownerA = $this->makeOwner();
        $ownerB = $this->makeOwner();

        AiFile::query()->create([
            'owner_type' => $ownerA->getMorphClass(),
            'owner_id' => $ownerA->getAuthIdentifier(),
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'a.txt',
            'stored_name' => 'a.txt',
            'file_path' => 'ai-files/a.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_READY,
        ]);

        // Owner A is at the cap (1 file); Owner B has none yet, so B's
        // own upload must succeed - the cap is per-owner, never global.
        $uploaded = UploadedFile::fake()->create('b.txt', 1, 'text/plain');
        $file = app(AiFileEngine::class)->storeUploadedFile($ownerB, $uploaded);

        $this->assertNotNull($file->id);
    }
}
