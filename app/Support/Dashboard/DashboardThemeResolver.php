<?php

namespace App\Support\Dashboard;

use App\Enums\Status;
use App\Models\DashboardTheme;
use Illuminate\Support\Str;

class DashboardThemeResolver
{
    /**
     * @return array{path: string, base: string, slug: string|null}
     */
    public function context(): array
    {
        $path = $this->resolvePath();

        return [
            'path' => $path,
            'base' => '/dashboard/themes/'.$path,
            'slug' => $this->resolvedSlug,
        ];
    }

    private ?string $resolvedSlug = null;

    public function resolvePath(): string
    {
        $fallback = $this->sanitizePath(config('dashboard.fallback_path', 'theme-1'));

        $theme = DashboardTheme::query()
            ->where('is_default', true)
            ->where('status', Status::Active)
            ->first();

        if ($theme !== null) {
            $candidate = $this->sanitizePath($theme->path);

            if ($this->themeDirectoryExists($candidate)) {
                $this->resolvedSlug = $theme->slug;

                return $candidate;
            }
        }

        $this->resolvedSlug = null;

        if ($this->themeDirectoryExists($fallback)) {
            return $fallback;
        }

        return 'theme-1';
    }

    private function sanitizePath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '' || Str::contains($path, '..')) {
            return config('dashboard.fallback_path', 'theme-1');
        }

        return $path;
    }

    private function themeDirectoryExists(string $path): bool
    {
        return is_dir(public_path('dashboard/themes/'.$path));
    }
}
