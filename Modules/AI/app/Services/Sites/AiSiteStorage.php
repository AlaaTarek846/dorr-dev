<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;

/** All disk access of the website builder: private disk only, never public/. */
class AiSiteStorage
{
    public const MIME = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * @return array<string, string> path => content of a completed version
     */
    public function versionFiles(AiSiteVersion $version): array
    {
        $files = [];

        foreach ((array) $version->files as $entry) {
            $path = $entry['path'] ?? null;

            if (is_string($path) && $this->disk()->exists($version->path().'/'.$path)) {
                $files[$path] = (string) $this->disk()->get($version->path().'/'.$path);
            }
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     * @return array{manifest: list<array{path: string, size: int}>, total: int}
     */
    public function writeVersion(AiSiteVersion $version, array $files): array
    {
        $this->disk()->deleteDirectory($version->path());
        $manifest = [];
        $total = 0;

        foreach ($files as $path => $content) {
            $this->disk()->put($version->path().'/'.$path, $content);
            $manifest[] = ['path' => $path, 'size' => strlen($content)];
            $total += strlen($content);
        }

        return ['manifest' => $manifest, 'total' => $total];
    }

    public function readVersionFile(AiSiteVersion $version, string $path): ?string
    {
        $known = collect((array) $version->files)->contains(fn ($entry) => ($entry['path'] ?? null) === $path);

        return $known && $this->disk()->exists($version->path().'/'.$path)
            ? (string) $this->disk()->get($version->path().'/'.$path)
            : null;
    }

    public function storeAsset(AiSiteProject $project, UploadedFile $file, string $baseName): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $name = $baseName.'.'.$extension;

        $this->disk()->putFileAs($project->assetsPath(), $file, $name);

        return 'assets/'.$name;
    }

    public function readAsset(AiSiteProject $project, string $path): ?string
    {
        $name = substr($path, strlen('assets/'));

        if ($name === '' || str_contains($name, '/') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
            return null;
        }

        $full = $project->assetsPath().'/'.$name;

        return $this->disk()->exists($full) ? (string) $this->disk()->get($full) : null;
    }

    /** @return list<string> asset file names (without the assets/ prefix) */
    public function assetNames(AiSiteProject $project): array
    {
        return array_map('basename', $this->disk()->files($project->assetsPath()));
    }

    public function deleteProject(AiSiteProject $project): void
    {
        $this->disk()->deleteDirectory('ai-sites/'.$project->id);
    }

    public function mimeFor(string $path): string
    {
        return self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
