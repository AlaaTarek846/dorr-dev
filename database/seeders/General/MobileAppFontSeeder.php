<?php

namespace Database\Seeders\General;

use App\Models\MobileAppFont;
use App\Support\Mobile\MobileFontWeightGuesser;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MobileAppFontSeeder extends Seeder
{
    public function run(): void
    {
        $directory = $this->fontsDirectory();

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory);
        }

        $this->ensureCairoInFontsDirectory($directory);

        $files = collect(File::glob($directory.DIRECTORY_SEPARATOR.'*.{ttf,otf}', GLOB_BRACE))
            ->filter(fn (string $path) => is_file($path))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->command?->warn('No .ttf/.otf files in '.$directory.'; skipped mobile app fonts seed.');

            return;
        }

        $defaultSlug = (string) config('mobile_appearance.default_font_slug', 'cairo');
        $sort = 0;
        $seededIds = [];

        foreach ($files as $absolutePath) {
            $fileName = basename($absolutePath);
            $slug = $this->slugFromFileName($fileName);
            $name = $this->nameFromFileName($fileName);
            $isDefault = $slug === $defaultSlug;

            $font = MobileAppFont::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'status' => true,
                    'is_default' => $isDefault,
                    'sort_order' => $isDefault ? 0 : ++$sort,
                ],
            );

            $seededIds[] = $font->id;
            $this->attachFontFileIfMissing($font, $absolutePath, $fileName);
        }

        MobileAppFont::query()
            ->whereNotIn('id', $seededIds)
            ->update(['is_default' => false]);

        MobileAppFont::query()
            ->where('slug', $defaultSlug)
            ->update(['is_default' => true, 'sort_order' => 0]);
    }

    private function fontsDirectory(): string
    {
        $relative = (string) config('mobile_appearance.fonts_seed_directory', 'fonts');

        return base_path(str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    private function ensureCairoInFontsDirectory(string $directory): void
    {
        $target = $directory.DIRECTORY_SEPARATOR.'cairo.ttf';

        if (is_file($target)) {
            return;
        }

        foreach (config('mobile_appearance.cairo_font_fallback_paths', []) as $relative) {
            $source = base_path(str_replace('/', DIRECTORY_SEPARATOR, $relative));

            if (! is_file($source)) {
                continue;
            }

            File::copy($source, $target);
            $this->command?->info('Copied cairo.ttf into fonts/ for seeding.');

            return;
        }

        $this->command?->warn('cairo.ttf not found in fonts/ or fallbacks.');
    }

    private function slugFromFileName(string $fileName): string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        if (strtolower($base) === 'cairo') {
            return 'cairo';
        }

        $slug = Str::slug(str_replace('_', '-', $base));

        return $slug !== '' ? $slug : 'font-'.substr(md5($fileName), 0, 8);
    }

    private function nameFromFileName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    private function attachFontFileIfMissing(MobileAppFont $font, string $absolutePath, string $fileName): void
    {
        if ($font->getMedia(MobileAppFont::FONT_FILES_COLLECTION)->isNotEmpty()) {
            return;
        }

        $mime = str_ends_with(strtolower($fileName), '.otf') ? 'font/otf' : 'font/ttf';
        $upload = new UploadedFile($absolutePath, $fileName, $mime, null, true);
        $weight = MobileFontWeightGuesser::fromFileName($fileName);
        $properties = ['weight' => $weight];

        if (strtolower(pathinfo($fileName, PATHINFO_FILENAME)) === 'cairo') {
            $properties['variable'] = true;
            $properties['weights'] = '200-1000';
        }

        $font->addMedia($upload)
            ->usingFileName($fileName)
            ->withCustomProperties($properties)
            ->toMediaCollection(MobileAppFont::FONT_FILES_COLLECTION);
    }
}
