<?php

namespace Modules\AI\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Modules\AI\Rules\SafeUploadedFile;
use Tests\TestCase;

/**
 * v2.0 requirements doc S15.5 (malicious-content check on uploads). Real
 * bytes are used throughout (a genuine tiny PNG, a genuine PE header, a
 * genuine shebang line) rather than asserting against the rule's
 * internals, so this proves actual detection behaviour.
 */
class SafeUploadedFileTest extends TestCase
{
    protected function tmpFile(string $contents, string $name = 'upload.bin'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'safe-upload-test-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function fails(UploadedFile $file): bool
    {
        $failed = false;
        (new SafeUploadedFile())->validate('attachment', $file, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_a_genuine_png_passes(): void
    {
        // Smallest possible valid PNG (1x1 transparent pixel).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        $this->assertFalse($this->fails($this->tmpFile($png, 'photo.png')));
    }

    public function test_a_windows_executable_disguised_as_a_jpg_is_rejected(): void
    {
        // "MZ" + padding is enough to be a real PE header signature.
        $exe = "MZ".str_repeat("\x00", 62)."\x00\x00\x00\x00";

        $this->assertTrue($this->fails($this->tmpFile($exe, 'photo.jpg')));
    }

    public function test_a_shell_script_disguised_as_a_txt_is_rejected(): void
    {
        $script = "#!/bin/bash\nrm -rf /\n";

        $this->assertTrue($this->fails($this->tmpFile($script, 'notes.txt')));
    }

    public function test_an_embedded_php_tag_is_rejected_even_with_an_allowed_extension(): void
    {
        $phpShell = "GIF89a\n<?php system(\$_GET['c']); ?>";

        $this->assertTrue($this->fails($this->tmpFile($phpShell, 'image.gif')));
    }

    public function test_a_genuine_pdf_header_passes(): void
    {
        $pdf = "%PDF-1.4\n%".str_repeat('A', 200);

        $this->assertFalse($this->fails($this->tmpFile($pdf, 'document.pdf')));
    }
}
