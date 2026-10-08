<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Acceptance criteria doc S29 (end-to-end acceptance test) and S23
 * (security tests), run against the real HTTP routes
 * (user/v1/ai-files/*) with real Sanctum auth - same layer as
 * AiChatConversationIsolationTest, because that is the actual attack
 * surface these protections exist for.
 */
class AiFileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Bus::fake();
    }

    protected function makeUser(string $label = 'User'): User
    {
        return User::query()->create([
            'name' => $label.' '.uniqid(),
            'email' => 'file-api-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * Doc S29, steps 1-9: upload as User A, verify the HTTP response, the
     * database record, the checksum, the storage object, the status, and
     * that User A can retrieve it.
     */
    public function test_a_user_can_upload_and_retrieve_their_own_file(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $upload = UploadedFile::fake()->createWithContent('report.txt', 'Real uploaded content for the test.');

        $response = $this->postJson('/api/user/v1/ai-files', ['file' => $upload]);

        $response->assertCreated();
        $fileId = $response->json('data.id');
        $this->assertNotNull($fileId);
        $this->assertSame('report.txt', $response->json('data.file_name'));
        $this->assertArrayNotHasKey('file_path', $response->json('data'));

        $file = AiFile::query()->findOrFail($fileId);
        $this->assertTrue($file->isOwnedBy($owner));
        $this->assertNotNull($file->checksum);
        $this->assertSame(AiFile::STATUS_VALIDATING, $file->processing_status);
        $this->assertTrue(Storage::disk('public')->exists($file->file_path));

        Bus::assertDispatched(ProcessAiFileJob::class, fn ($job) => $job->file->is($file));

        $show = $this->getJson("/api/user/v1/ai-files/{$fileId}");
        $show->assertOk();
        $this->assertSame($fileId, $show->json('data.id'));

        $status = $this->getJson("/api/user/v1/ai-files/{$fileId}/status");
        $status->assertOk();
        $this->assertSame(AiFile::STATUS_VALIDATING, $status->json('data.status'));
    }

    /**
     * Doc S29, steps 10-15: User B must never reach User A's file, and
     * once User A deletes it, User A must not reach it either.
     */
    public function test_full_acceptance_flow_upload_isolate_delete(): void
    {
        $userA = $this->makeUser('A');
        $userB = $this->makeUser('B');

        Sanctum::actingAs($userA, ['*'], 'user_api');
        $upload = UploadedFile::fake()->createWithContent('shared.txt', 'Only User A should ever see this.');
        $fileId = $this->postJson('/api/user/v1/ai-files', ['file' => $upload])->json('data.id');
        $file = AiFile::query()->findOrFail($fileId);

        Sanctum::actingAs($userB, ['*'], 'user_api');
        $this->getJson("/api/user/v1/ai-files/{$fileId}")->assertNotFound();
        $this->deleteJson("/api/user/v1/ai-files/{$fileId}")->assertNotFound();
        $this->assertFalse($file->fresh()->trashed(), 'soft delete must not have happened via User B\'s request');
        $this->assertTrue(Storage::disk('public')->exists($file->file_path), 'User B must not be able to trigger storage cleanup either');

        Sanctum::actingAs($userA, ['*'], 'user_api');
        $this->deleteJson("/api/user/v1/ai-files/{$fileId}")->assertStatus(204);

        $this->assertSoftDeleted($file);
        $this->assertFalse(Storage::disk('public')->exists($file->file_path), 'storage object must be cleaned up on delete');

        $this->getJson("/api/user/v1/ai-files/{$fileId}")->assertNotFound();
    }

    public function test_a_file_exceeding_the_configured_size_limit_is_rejected(): void
    {
        config(['ai.files.max_size_bytes' => 10]);

        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $upload = UploadedFile::fake()->createWithContent('too-big.txt', str_repeat('x', 1000));

        $response = $this->postJson('/api/user/v1/ai-files', ['file' => $upload]);

        $response->assertStatus(422);
    }

    /** Real zip bytes (finfo reports application/zip): archives are not on the allowlist. */
    protected function realZipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'hello');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function test_an_unsupported_mime_type_is_a_clean_error_not_a_server_error(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $upload = UploadedFile::fake()->createWithContent('archive.zip', $this->realZipBytes());

        $response = $this->postJson('/api/user/v1/ai-files', ['file' => $upload]);

        $response->assertStatus(422);
        $this->assertSame('UNSUPPORTED_FILE_TYPE', $response->json('error_code'));
    }

    /**
     * Doc S23: double extension (document.pdf.exe) must never be
     * accepted just because its last segment looks safe - the exe
     * segment anywhere in the name is enough to reject it.
     */
    public function test_a_double_extension_disguising_an_executable_is_rejected(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $upload = UploadedFile::fake()->createWithContent('invoice.pdf.exe', str_repeat('x', 50));

        $response = $this->postJson('/api/user/v1/ai-files', ['file' => $upload]);

        $response->assertStatus(422);
        $this->assertSame('FILE_REJECTED_SECURITY', $response->json('error_code'));
    }

    /**
     * Doc S7/S23 (MIME spoofing): a file whose real bytes are an
     * executable, renamed with a harmless-looking .pdf extension, must
     * not be accepted as a PDF just because of its extension/claimed
     * MIME type.
     */
    public function test_mime_spoofing_is_caught_by_real_content_inspection(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        // MZ header - the real signature finfo recognizes as a Windows
        // executable, regardless of filename or claimed MIME type.
        $exeBytes = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00";
        $upload = UploadedFile::fake()->createWithContent('report.pdf', $exeBytes)
            ->mimeType('application/pdf');

        $response = $this->postJson('/api/user/v1/ai-files', ['file' => $upload]);

        $response->assertStatus(422);
    }

    public function test_reuploading_identical_content_reuses_the_checksum_match_instead_of_reprocessing(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $first = $this->postJson('/api/user/v1/ai-files', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'identical bytes'),
        ])->json('data.id');

        AiFile::query()->whereKey($first)->update(['processing_status' => AiFile::STATUS_READY]);

        $second = $this->postJson('/api/user/v1/ai-files', [
            'file' => UploadedFile::fake()->createWithContent('b.txt', 'identical bytes'),
        ])->json('data.id');

        $this->assertSame($first, $second);
    }
}
