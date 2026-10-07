<?php

namespace Modules\AI\Tests\Unit;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\ProcessAiFileJob;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\AiFileEngine;
use Modules\User\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Proves AiFileEngine::process() itself - real MIME detection (a
 * mismatched client-supplied MIME is corrected, master plan #5), real
 * checksum dedup (master plan #42), and an honest rejection for a type
 * with no real processor yet (master plan #46) - rather than the full
 * sendMessage() -> storeAttachment() wiring, which is covered manually
 * by uploading a real file and checking the resulting ai_files row (see
 * the final report for exactly what to check).
 */
class AiFileEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'File Engine Test Owner',
            'email' => 'file-engine-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_supported_file_is_registered_and_queued_for_processing(): void
    {
        Storage::fake('public');
        Bus::fake();

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/note.txt', 'Hello, this is a real note.');

        $file = app(AiFileEngine::class)->process(
            owner: $owner,
            diskPath: 'ai-chat/test/note.txt',
            originalName: 'note.txt',
            clientMimeType: 'text/plain',
            size: 28,
        );

        $this->assertSame(AiFile::STATUS_VALIDATING, $file->processing_status);
        $this->assertNotNull($file->checksum);
        $this->assertSame('text/plain', $file->mime_type);

        Bus::assertDispatched(ProcessAiFileJob::class, fn ($job) => $job->file->is($file));
    }

    /**
     * Master plan #5: "never trust the extension alone" - a file named
     * like a text file but whose real bytes are a PDF must be detected
     * and corrected, not routed by the client-supplied MIME type.
     */
    public function test_the_real_file_content_is_detected_even_when_the_client_mime_type_is_wrong(): void
    {
        Storage::fake('public');
        Bus::fake();

        $owner = $this->makeOwner();
        $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
        Storage::disk('public')->put('ai-chat/test/fake-name.txt', $pdfBytes);

        $file = app(AiFileEngine::class)->process(
            owner: $owner,
            diskPath: 'ai-chat/test/fake-name.txt',
            originalName: 'fake-name.txt',
            clientMimeType: 'text/plain',
            size: strlen($pdfBytes),
        );

        $this->assertSame('application/pdf', $file->mime_type);
    }

    /** Real zip bytes (finfo reports application/zip): archives are not on the allowlist. */
    protected function realZipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'hello');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function test_a_type_with_no_real_processor_is_rejected_honestly_instead_of_stuck_pending(): void
    {
        Storage::fake('public');
        Bus::fake();

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/archive.zip', $this->realZipBytes());

        $file = app(AiFileEngine::class)->process(
            owner: $owner,
            diskPath: 'ai-chat/test/archive.zip',
            originalName: 'archive.zip',
            clientMimeType: 'application/zip',
            size: 100,
        );

        $this->assertSame(AiFile::STATUS_FAILED, $file->processing_status);
        $this->assertSame('unsupported_file_type', $file->processing_error);
        Bus::assertNotDispatched(ProcessAiFileJob::class);
    }

    public function test_reuploading_the_same_file_content_reuses_the_already_ready_row(): void
    {
        Storage::fake('public');
        Bus::fake();

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/first.txt', 'Same content both times.');

        $engine = app(AiFileEngine::class);

        $first = $engine->process(
            owner: $owner,
            diskPath: 'ai-chat/test/first.txt',
            originalName: 'first.txt',
            clientMimeType: 'text/plain',
            size: 25,
        );
        $first->update(['processing_status' => AiFile::STATUS_READY]);

        Storage::disk('public')->put('ai-chat/test/second.txt', 'Same content both times.');

        $second = $engine->process(
            owner: $owner,
            diskPath: 'ai-chat/test/second.txt',
            originalName: 'second.txt',
            clientMimeType: 'text/plain',
            size: 25,
        );

        $this->assertTrue($second->is($first));
    }

    /**
     * Doc S4/S20 (delete): Phase 5 changed delete() from removing one
     * named content-reference file to removing the whole
     * `ai-files/{id}/` directory on the `local` disk, since that
     * directory can now also hold thumbnail/preview images alongside
     * extracted-text.txt/blocks.json - proves none of those artifacts
     * are left behind as orphaned files after delete().
     */
    public function test_delete_removes_the_original_file_and_every_content_reference_artifact(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->makeOwner();
        Storage::disk('public')->put('ai-chat/test/to-delete.txt', 'Will be deleted.');

        $file = AiFile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'to-delete.txt',
            'file_path' => 'ai-chat/test/to-delete.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'file_size' => 17,
            'processing_status' => AiFile::STATUS_READY,
        ]);

        // Simulate what ProcessAiFileJob would have written for this
        // file: extracted text, blocks, AND (Phase 5) preview assets.
        Storage::disk('local')->put("ai-files/{$file->id}/extracted-text.txt", 'text');
        Storage::disk('local')->put("ai-files/{$file->id}/blocks.json", '[]');
        Storage::disk('local')->put("ai-files/{$file->id}/thumbnail.jpg", 'fake-bytes');
        Storage::disk('local')->put("ai-files/{$file->id}/preview.png", 'fake-bytes');

        app(AiFileEngine::class)->delete($file);

        $this->assertFalse(Storage::disk('public')->exists('ai-chat/test/to-delete.txt'));
        $this->assertFalse(Storage::disk('local')->exists("ai-files/{$file->id}"));
        $this->assertSoftDeleted('ai_files', ['id' => $file->id]);
    }

    /**
     * Root-cause fix - real, observed gap: the inline single-attachment
     * chat flow (AiChatService::buildDocumentText()) used to call the
     * older, pre-File-Engine AiDocumentTextExtractor instead of this
     * Engine's own real per-format processors - silently missing XLSX
     * entirely (AiDocumentTextExtractor::supports() never recognized
     * it) and using a weaker extractor for DOCX even though a real
     * PhpWord-backed WordFileProcessor already existed. This proves
     * extractTextSync() - the method buildDocumentText() now actually
     * calls - genuinely dispatches to the real ExcelFileProcessor and
     * reads real cell data via PhpSpreadsheet, round-tripped through the
     * real writer and reader exactly like ExcelFileProcessorTest does,
     * rather than returning null the way the old extractor silently did
     * for every XLSX file.
     */
    public function test_extract_text_sync_reads_a_real_xlsx_file_via_the_real_excel_processor(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Product');
        $sheet->setCellValue('B1', 'Price');
        $sheet->setCellValue('A2', 'Widget');
        $sheet->setCellValue('B2', 42);

        $path = sys_get_temp_dir().'/ai-file-engine-extract-sync-test-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $text = app(AiFileEngine::class)->extractTextSync(
            $path,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        @unlink($path);

        $this->assertNotNull($text);
        $this->assertStringContainsString('Widget', $text);
        $this->assertStringContainsString('42', $text);
    }

    public function test_extract_text_sync_returns_null_for_an_unsupported_mime_type(): void
    {
        $text = app(AiFileEngine::class)->extractTextSync('/tmp/does-not-matter', 'application/x-made-up-type');

        $this->assertNull($text);
    }

    public function test_supports_sync_reflects_the_same_registry_extract_text_sync_uses(): void
    {
        $engine = app(AiFileEngine::class);

        $this->assertTrue($engine->supportsSync('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertTrue($engine->supportsSync('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertFalse($engine->supportsSync('application/x-made-up-type'));
    }
}
