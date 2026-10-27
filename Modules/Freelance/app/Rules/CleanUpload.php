<?php

namespace Modules\Freelance\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Modules\Freelance\Services\Security\UploadScanner;

/**
 * FL-1205: add to the rules of any upload (`['file', 'mimes:…', new CleanUpload]`). The file's real bytes are checked by
 * `UploadScanner`; a refused file answers 422 with a plain message and is written to the log (who tried, what, why) - never kept.
 */
class CleanUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        $why = app(UploadScanner::class)->inspect($value);
        if ($why === null) {
            return;
        }
        $actor = request()?->user();
        Log::warning('[Freelance] upload refused', ['why' => $why, 'name' => $value->getClientOriginalName(), 'by' => $actor ? class_basename($actor).'#'.$actor->getKey() : null, 'ip' => request()?->ip()]);
        $fail($why === 'scanner_unavailable' ? __('freelance.file_scan_unavailable') : __('freelance.file_rejected'));
    }
}
