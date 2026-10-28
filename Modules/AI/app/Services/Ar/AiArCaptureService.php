<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArCapture;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArSetting;

/**
 * Photos/videos taken inside an AR experience show a room, so they are kept on the private disk only,
 * need an explicit consent flag, expire after the admin-set number of days, and can be deleted by their owner.
 */
class AiArCaptureService
{
    private const TYPES = ['photo' => ['image/jpeg', 'image/png'], 'video' => ['video/mp4']];

    private const MAX_BYTES = 50 * 1024 * 1024;

    public function store(AiArExperience $experience, UploadedFile $file, string $kind, bool $consent): AiArCapture
    {
        if (! $consent) {
            throw new AiArException('consent_required', 422);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! isset(self::TYPES[$kind]) || ! in_array($mime, self::TYPES[$kind], true) || $file->getSize() > self::MAX_BYTES) {
            throw new AiArException('capture_invalid', 422);
        }

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'video/mp4' => 'mp4'][$mime];
        $path = $file->storeAs('ai-ar/captures/'.$experience->id, Str::uuid().'.'.$ext, 'local');

        return AiArCapture::query()->create([
            'experience_id' => $experience->id, 'kind' => $kind, 'file_path' => $path, 'consent_recorded' => true,
            'expires_at' => now()->addDays(AiArSetting::current()->capture_retention_days),
        ]);
    }

    public function delete(AiArCapture $capture): void
    {
        Storage::disk('local')->delete($capture->file_path);
        $capture->delete();
    }

    public function purgeExpired(): int
    {
        $count = 0;

        AiArCapture::query()->where('expires_at', '<', now())->each(function (AiArCapture $c) use (&$count) {
            $this->delete($c);
            $count++;
        });

        return $count;
    }
}
