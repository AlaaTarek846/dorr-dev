<?php

namespace Modules\Freelance\Services\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * The check every file goes through before the freelance module keeps it (FL-1205): portfolio images, certificates and ID documents,
 * offer attachments, deliveries, dispute evidence, chat files, project attachments and avatars.
 *
 * Be clear about what this is: a check of the file's real bytes - not its name - for what has no place in this module (programs, scripts,
 * pages that run code, disguised files, PDFs with active content, archives hiding programs or macros, archive bombs). It is NOT an
 * antivirus. When a ClamAV daemon is configured (`freelance.uploads.clamav`) every file is also streamed to it and a "FOUND" answer
 * refuses the file; with no daemon the checks below are all there is.
 *
 * `inspect()` answers null for a clean file or a short reason code; nothing is thrown, nothing is changed in the file.
 */
class UploadScanner
{
    /** A name with any of these as a "extension" is refused, wherever it sits (`report.pdf.exe`). */
    public const BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'com', 'scr', 'msi', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta', 'lnk', 'reg', 'cpl', 'sh', 'bash',
        'jar', 'apk', 'ipa', 'app', 'deb', 'rpm', 'dmg', 'iso', 'php', 'phtml', 'phar', 'pl', 'py', 'rb', 'cgi', 'asp', 'aspx', 'jsp', 'html', 'htm',
        'xhtml', 'svg', 'swf', 'docm', 'xlsm', 'pptm', 'dotm', 'xlam',
    ];

    /** What the real content may not be, whatever the name says. */
    private const BLOCKED_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-pie-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/x-elf', 'application/vnd.microsoft.portable-executable', 'application/x-sh', 'application/x-shellscript',
        'text/x-shellscript', 'text/x-php', 'application/x-httpd-php', 'application/x-php', 'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/javascript', 'text/javascript', 'application/x-msi', 'application/java-archive', 'application/vnd.android.package-archive',
        'text/x-python', 'text/x-perl', 'application/x-bat', 'application/x-msdos-program',
    ];

    /** The kinds of file whose name must match their bytes (a "photo.png" that is a program is refused). */
    private const IMAGE_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    private const ZIP_MAX_ENTRIES = 2000;

    private const ZIP_MAX_UNPACKED = 1_073_741_824;

    private const ZIP_MAX_RATIO = 200;

    /** @return string|null why the file was refused (a short code), or null when it is clean */
    public function inspect(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return 'unreadable';
        }
        $name = strtolower($file->getClientOriginalName());
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        foreach (array_slice(explode('.', $name), 1) as $part) {
            if (in_array($part, self::BLOCKED_EXTENSIONS, true)) {
                return 'blocked_extension';
            }
        }

        $head = (string) @file_get_contents($path, false, null, 0, 262144);
        $mime = $this->realMime($path);
        if ($mime === null || in_array($mime, self::BLOCKED_MIMES, true)) {
            return 'blocked_type';
        }
        if ($this->isProgram($head)) {
            return 'program';
        }
        if (str_starts_with($head, '#!') || stripos($head, '<?php') !== false || str_contains($head, '<?=')) {
            return 'script';
        }
        if (isset(self::IMAGE_EXT[$ext]) && ! str_starts_with($mime, 'image/')) {
            return 'disguised';
        }
        if ($ext === 'pdf' && $mime !== 'application/pdf') {
            return 'disguised';
        }

        if ($mime === 'application/pdf' && $this->pdfHasActiveContent($path)) {
            return 'pdf_active_content';
        }
        if (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) || str_contains($mime, 'openxmlformats') || str_starts_with($head, 'PK')) {
            if (($why = $this->zipProblem($path)) !== null) {
                return $why;
            }
        }
        // Old Office files (doc / xls / ppt) keep macros in a "_VBA_PROJECT" stream.
        if (in_array($ext, ['doc', 'xls', 'ppt'], true) && $this->hasOleMacros($path)) {
            return 'macros';
        }

        return $this->viaClamd($path);
    }

    private function realMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $path) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        return is_string($mime) ? $mime : null;
    }

    /** A Windows program (an "MZ" header whose PE pointer lands on "PE"), a Linux one (ELF) or a macOS one (Mach-O). */
    private function isProgram(string $head): bool
    {
        if (str_starts_with($head, "\x7fELF") || in_array(substr($head, 0, 4), ["\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xFE\xED\xFA\xCF", "\xFE\xED\xFA\xCE", "\xCA\xFE\xBA\xBE"], true)) {
            return true;
        }
        if (str_starts_with($head, 'MZ') && strlen($head) >= 0x40) {
            $pe = unpack('V', substr($head, 0x3C, 4))[1] ?? 0;

            return $pe > 0 && $pe < strlen($head) - 4 && substr($head, $pe, 4) === "PE\0\0";
        }

        return false;
    }

    /** /Launch and JavaScript make a PDF run something when it opens. */
    private function pdfHasActiveContent(string $path): bool
    {
        $raw = (string) @file_get_contents($path, false, null, 0, 8 * 1024 * 1024);

        return preg_match('#/(Launch|JavaScript|RichMedia)\b#', $raw) === 1 || preg_match('#/JS\s*[\(<\[]#', $raw) === 1;
    }

    /** A damaged archive, a bomb, a way out of the folder, a program or a macro inside. */
    private function zipProblem(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return 'archive_unreadable';
        }
        try {
            if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
                return 'archive_bomb';
            }
            $unpacked = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return 'archive_unreadable';
                }
                $entry = strtolower(str_replace('\\', '/', (string) $stat['name']));
                if (str_contains($entry, '../') || str_starts_with($entry, '/')) {
                    return 'archive_path';
                }
                if (str_ends_with($entry, '/vbaproject.bin')) {
                    return 'macros';
                }
                foreach (array_slice(explode('.', basename($entry)), 1) as $part) {
                    if (in_array($part, self::BLOCKED_EXTENSIONS, true)) {
                        return 'archive_program';
                    }
                }
                $unpacked += (int) $stat['size'];
                if ($stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > self::ZIP_MAX_RATIO && $stat['size'] > 50 * 1024 * 1024) {
                    return 'archive_bomb';
                }
            }
            if ($unpacked > self::ZIP_MAX_UNPACKED) {
                return 'archive_bomb';
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    private function hasOleMacros(string $path): bool
    {
        $raw = (string) @file_get_contents($path, false, null, 0, 16 * 1024 * 1024);

        return str_contains($raw, "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T\0");
    }

    /** The optional ClamAV daemon: "FOUND" refuses; an unreachable daemon refuses too unless `fail_closed` is off. */
    private function viaClamd(string $path): ?string
    {
        $c = (array) config('freelance.uploads.clamav');
        if (empty($c['enabled'])) {
            return null;
        }
        $socket = @stream_socket_client('tcp://'.($c['host'] ?? '127.0.0.1').':'.(int) ($c['port'] ?? 3310), $errno, $err, (float) ($c['timeout'] ?? 10));
        if ($socket === false) {
            Log::warning('[Freelance] ClamAV is not reachable: '.$err);

            return ($c['fail_closed'] ?? true) ? 'scanner_unavailable' : null;
        }
        stream_set_timeout($socket, (int) ($c['timeout'] ?? 10));
        try {
            fwrite($socket, "zINSTREAM\0");
            $in = fopen($path, 'rb');
            while ($in !== false && ! feof($in)) {
                $chunk = fread($in, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            if ($in !== false) {
                fclose($in);
            }
            fwrite($socket, pack('N', 0));
            $answer = trim((string) stream_get_contents($socket));
        } finally {
            fclose($socket);
        }
        if (str_contains($answer, 'FOUND')) {
            return 'malware';
        }
        if (! str_contains($answer, 'OK')) {
            Log::warning('[Freelance] ClamAV answered: '.$answer);

            return ($c['fail_closed'] ?? true) ? 'scanner_unavailable' : null;
        }

        return null;
    }
}
