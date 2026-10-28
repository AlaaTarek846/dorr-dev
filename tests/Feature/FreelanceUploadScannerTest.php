<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Modules\Freelance\Services\Security\UploadScanner;
use Tests\TestCase;
use ZipArchive;

/** FL-1205: files are checked by their real bytes before the freelance module keeps them. */
class FreelanceUploadScannerTest extends TestCase
{
    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function zip(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $body) {
            $zip->addFromString($entry, $body);
        }
        $zip->close();

        return $this->file($name, (string) file_get_contents($path));
    }

    private function pe(): string
    {
        return 'MZ'.str_repeat("\0", 0x3A).pack('V', 0x80).str_repeat("\0", 0x40).("PE\0\0").str_repeat("\0", 200);
    }

    public function test_clean_files_pass(): void
    {
        $scan = app(UploadScanner::class);
        $this->assertNull($scan->inspect(UploadedFile::fake()->image('photo.png', 300, 300)));
        $this->assertNull($scan->inspect($this->file('brief.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF")));
        $this->assertNull($scan->inspect($this->file('notes.txt', "Just some notes.\nSecond line.")));
        $this->assertNull($scan->inspect($this->zip('site.zip', ['index.txt' => 'hello', 'assets/logo.png' => 'x'])));
    }

    public function test_programs_scripts_and_disguised_files_are_refused(): void
    {
        $scan = app(UploadScanner::class);
        // A Windows program called a picture, a Linux one called a pdf, a script called a note, php hidden in a jpg, a double extension.
        $this->assertNotNull($scan->inspect($this->file('photo.png', $this->pe())));
        $this->assertNotNull($scan->inspect($this->file('report.pdf', "\x7fELF".str_repeat("\0", 200))));
        $this->assertNotNull($scan->inspect($this->file('notes.txt', "#!/bin/sh\nrm -rf /\n")));
        $this->assertNotNull($scan->inspect($this->file('photo.jpg', "\xFF\xD8\xFF\xE0<?php system(\$_GET['c']); ?>")));
        $this->assertNotNull($scan->inspect($this->file('invoice.pdf.exe', 'anything')));
        $this->assertNotNull($scan->inspect($this->file('page.html', '<html><script>alert(1)</script></html>')));
        // A name that says "picture" over bytes that are not one.
        $this->assertSame('disguised', $scan->inspect($this->file('photo.png', 'this is plain text, not a picture')));
    }

    public function test_pdfs_with_active_content_are_refused(): void
    {
        $scan = app(UploadScanner::class);
        $this->assertSame('pdf_active_content', $scan->inspect($this->file('form.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj\n%%EOF")));
        $this->assertSame('pdf_active_content', $scan->inspect($this->file('go.pdf', "%PDF-1.4\n1 0 obj\n<< /S /Launch /F (cmd.exe) >>\nendobj\n%%EOF")));
    }

    public function test_archives_hiding_programs_macros_or_bombs_are_refused(): void
    {
        $scan = app(UploadScanner::class);
        $this->assertSame('archive_program', $scan->inspect($this->zip('files.zip', ['readme.txt' => 'hi', 'tools/run.exe' => 'MZ'])));
        $this->assertSame('archive_program', $scan->inspect($this->zip('files.zip', ['report.pdf.js' => 'x'])));
        $this->assertSame('macros', $scan->inspect($this->zip('budget.docx', ['word/document.xml' => '<w/>', 'word/vbaProject.bin' => 'x'])));
        $this->assertSame('archive_bomb', $scan->inspect($this->zip('many.zip', array_combine(array_map(fn ($i) => "f{$i}.txt", range(1, 2001)), array_fill(0, 2001, 'x')))));
        $this->assertSame('archive_path', $scan->inspect($this->zip('out.zip', ['../outside.txt' => 'x'])));
    }

    public function test_a_clamav_that_cannot_be_reached_refuses_unless_told_otherwise(): void
    {
        $scan = app(UploadScanner::class);
        $clean = fn () => $this->file('notes.txt', 'hello there');
        config(['freelance.uploads.clamav' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1, 'fail_closed' => true]]);
        $this->assertSame('scanner_unavailable', $scan->inspect($clean()));
        config(['freelance.uploads.clamav.fail_closed' => false]);
        $this->assertNull($scan->inspect($clean()));
        config(['freelance.uploads.clamav.enabled' => false]);
        $this->assertNull($scan->inspect($clean()));
    }
}
