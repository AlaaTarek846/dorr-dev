<?php

namespace Database\Seeders\General;

use App\Http\Requests\General\PlatformSettingUpdateRequest;
use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

class PlatformSettingSeeder extends Seeder
{
    /**
     * Dashboard platform-settings collections → files under project favicon_io/.
     *
     * @var array<string, string>
     */
    private const MEDIA_SOURCES = [
        'logo' => 'logo.png',
        'logo_dark' => 'logo_dark.png',
        'favicon_ico' => 'favicon_ico.ico',
        'favicon_16' => 'favicon_16.png',
        'favicon_32' => 'favicon_32.png',
        'apple_touch_icon' => 'apple-touch-icon.png',
        'web_manifest' => 'site.webmanifest',
    ];

    public function run(): void
    {
        $setting = PlatformSetting::query()->firstOrCreate(
            ['id' => 1],
            ['app_name' => 'Dorr'],
        );

        if ($setting->app_name === '' || $setting->app_name === (string) config('app.name')) {
            $setting->update(['app_name' => 'Dorr']);
        }

        $baseDir = base_path('favicon_io');

        if (! is_dir($baseDir)) {
            $this->command?->warn('favicon_io directory missing; skipped platform branding media.');

            return;
        }

        foreach (PlatformSettingUpdateRequest::MEDIA_COLLECTIONS as $collection) {
            if ($setting->getSingleMedia($collection)) {
                continue;
            }

            $relative = self::MEDIA_SOURCES[$collection] ?? null;

            if ($relative === null || ! is_file($baseDir.DIRECTORY_SEPARATOR.$relative)) {
                $this->command?->warn("Missing favicon_io/{$relative} for {$collection}.");

                continue;
            }

            $path = $baseDir.DIRECTORY_SEPARATOR.$relative;
            $tempManifest = null;

            if ($collection === 'web_manifest') {
                $path = $this->prepareWebManifest($path);
                $tempManifest = $path;
            }

            $mime = mime_content_type($path) ?: $this->guessMime($relative);

            $file = new UploadedFile($path, basename($relative), $mime, null, true);
            $setting->setSingleMedia($collection, $file);

            if ($tempManifest !== null && is_file($tempManifest)) {
                File::delete($tempManifest);
            }
        }
    }

    private function prepareWebManifest(string $sourcePath): string
    {
        $data = json_decode((string) file_get_contents($sourcePath), true);

        if (! is_array($data)) {
            return $sourcePath;
        }

        $data['name'] = 'Dorr';
        $data['short_name'] = 'Dorr';

        $tempPath = storage_path('app/platform_setting_site.webmanifest');
        file_put_contents(
            $tempPath,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n",
        );

        return $tempPath;
    }

    private function guessMime(string $relative): string
    {
        return match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'webmanifest', 'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
