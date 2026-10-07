<?php

namespace Modules\AI\Services\Sites;

use Modules\AI\Exceptions\AiSiteException;

/**
 * Everything a generated file must pass before it is written to disk. The
 * model's output is untrusted: this refuses odd paths, odd file types, oversize
 * output and the handful of constructs that turn a "website" into a phishing or
 * tracking page. (Serving adds a CSP sandbox on top of this.)
 */
class AiSiteFileGuard
{
    public const ALLOWED_EXTENSIONS = ['html', 'htm', 'css', 'js', 'json', 'txt', 'xml'];

    /** Embeds we accept; any other iframe source is refused. */
    public const ALLOWED_IFRAME_HOSTS = ['www.google.com/maps', 'maps.google.com', 'www.youtube.com/embed', 'www.youtube-nocookie.com/embed', 'player.vimeo.com'];

    /**
     * Normalises a path or throws. Returns the clean relative path.
     */
    public function cleanPath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new AiSiteException('file_path_invalid');
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment) !== 1) {
                throw new AiSiteException('file_path_invalid');
            }
        }

        if (count($segments) > 4) {
            throw new AiSiteException('file_path_invalid');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new AiSiteException('file_type_not_allowed');
        }

        return $path;
    }

    /**
     * Validates the full resulting file set (after merging an edit onto the
     * previous version), not just what the model sent.
     *
     * @param  array<string, string>  $files  path => content
     */
    public function assertValid(array $files): void
    {
        $maxFiles = (int) config('ai.sites.max_files', 30);
        $maxFile = (int) config('ai.sites.max_file_bytes', 600000);
        $maxTotal = (int) config('ai.sites.max_total_bytes', 3000000);

        if (! isset($files['index.html'])) {
            throw new AiSiteException('generation_no_index');
        }

        if (count($files) > $maxFiles) {
            throw new AiSiteException('generation_too_many_files');
        }

        $total = 0;

        foreach ($files as $path => $content) {
            $this->cleanPath($path);
            $size = strlen($content);
            $total += $size;

            if ($size > $maxFile || $total > $maxTotal) {
                throw new AiSiteException('generation_too_large');
            }

            $this->assertSafeContent($path, $content);
        }
    }

    protected function assertSafeContent(string $path, string $content): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['html', 'htm', 'js'], true)) {
            return;
        }

        if (preg_match('/type\s*=\s*["\']?password/i', $content) === 1) {
            throw new AiSiteException('unsafe_content');
        }

        if (preg_match('/<form\b[^>]*\baction\s*=\s*["\']?\s*(?:https?:)?\/\//i', $content) === 1) {
            throw new AiSiteException('unsafe_content');
        }

        if (preg_match('/<meta\b[^>]*http-equiv\s*=\s*["\']?refresh/i', $content) === 1) {
            throw new AiSiteException('unsafe_content');
        }

        if (preg_match('/document\s*\.\s*cookie/i', $content) === 1) {
            throw new AiSiteException('unsafe_content');
        }

        if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*["\']?\s*(?:https?:)?\/\/([^"\'\s>]+)/i', $content, $matches) > 0) {
            foreach ($matches[1] as $source) {
                $allowed = false;

                foreach (self::ALLOWED_IFRAME_HOSTS as $host) {
                    if (str_starts_with(strtolower($source), $host)) {
                        $allowed = true;
                        break;
                    }
                }

                if (! $allowed) {
                    throw new AiSiteException('unsafe_content');
                }
            }
        }
    }
}
