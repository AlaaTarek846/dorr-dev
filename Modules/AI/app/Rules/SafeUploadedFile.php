<?php

namespace Modules\AI\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * v2.0 requirements doc S15.5 ("رفع الملفات يخضع لفحص النوع والحجم
 * والمحتوى الضار" - uploads are checked for type, size, and malicious
 * content). Type and size were already covered by `mimes:`/`max:` on
 * AiChatMessageRequest, but `mimes:` only trusts the file's *extension*
 * and Symfony's best-guess MIME - a renamed `.exe` saved as `photo.jpg`
 * sails straight through it.
 *
 * This is content-signature validation, not real antivirus scanning:
 * there is no ClamAV (or equivalent) available to run in this
 * environment, and building a fake scanner would be worse than being
 * honest that none exists. What this DOES catch, on the file's actual
 * bytes rather than its name: known dangerous binary/script signatures
 * (Windows PE, Linux ELF, shebang scripts, embedded PHP tags) and any
 * file whose real, content-sniffed MIME type doesn't match one of the
 * types this endpoint is meant to accept - i.e. a disguised executable
 * or script uploaded with an image/document extension.
 */
class SafeUploadedFile implements ValidationRule
{
    /**
     * @var list<string>
     */
    protected array $allowedRealMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
        'text/csv',
        // .docx/.xlsx are zip containers under the hood - finfo often
        // reports the container type rather than the office-specific one.
        'application/zip',
        // Voice messages recorded by the app (AAC in an .m4a/MP4 container) -
        // libmagic reports this container under either name depending on
        // version, so both are allowed rather than guessing one.
        'audio/mp4',
        'audio/x-m4a',
    ];

    /**
     * @var list<string>
     */
    protected array $dangerousSignatures = [
        "MZ",      // Windows PE executable (.exe/.dll)
        "\x7fELF", // Linux ELF executable
        "#!",      // shebang script (sh/bash/python/etc.)
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $path = $value->getRealPath();

        if ($path === false || ! is_readable($path)) {
            $fail(__('ai.attachment_rejected_content'));

            return;
        }

        // Real, observed gap: file_get_contents() can fail to even OPEN
        // the stream (not just fail to read it) if the file vanishes or
        // becomes locked between the is_readable() check above and this
        // call - a genuine TOCTOU race under concurrent requests, and
        // also what a real-time antivirus scanner can trigger by
        // quarantining a file the instant its content matches a known
        // malicious signature (exactly what this rule's own test
        // fixtures intentionally write). That failure raises a PHP
        // warning, not just a false return, and this app's error handler
        // escalates warnings into a real thrown exception - crashing the
        // whole request instead of the graceful "$head === false"
        // fallback this code already clearly intends. @-suppressed here
        // so a vanished/locked file is treated exactly like an unreadable
        // one: fails closed (rejected) via the finfo_file() check below,
        // never silently treated as safe.
        $head = @file_get_contents($path, false, null, 0, 4096);
        $head = $head === false ? '' : $head;

        foreach ($this->dangerousSignatures as $signature) {
            if (str_starts_with($head, $signature)) {
                $fail(__('ai.attachment_rejected_content'));

                return;
            }
        }

        if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) {
            $fail(__('ai.attachment_rejected_content'));

            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? @finfo_file($finfo, $path) : null;

        if ($finfo) {
            finfo_close($finfo);
        }

        if ($realMime === null || $realMime === false || ! in_array($realMime, $this->allowedRealMimes, true)) {
            $fail(__('ai.attachment_rejected_content'));
        }
    }
}
