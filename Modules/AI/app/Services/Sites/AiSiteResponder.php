<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;

/**
 * Builds the HTTP response for one file of a generated site - shared by the
 * preview link and by hosted sites. The content is untrusted: sandboxing CSP
 * (opaque origin), nosniff, no referrer, no cookies are ever involved.
 */
class AiSiteResponder
{
    private const TEXT = ['html', 'htm', 'css', 'js', 'json', 'txt', 'xml'];

    public function __construct(protected AiSiteStorage $storage, protected AiSiteTokenizer $tokenizer) {}

    /**
     * @param  bool  $hosted  published site: indexable and briefly cacheable; the preview is private (noindex, no-store)
     * @param  bool  $redirectRoot  send "/x" to "/x/" so relative links resolve
     */
    public function respond(Request $request, AiSiteProject $project, AiSiteVersion $version, string $path, bool $hosted, bool $redirectRoot): Response|RedirectResponse
    {
        // The site root can be reached with or without a trailing slash. Redirecting "/x" to "/x/" loops
        // behind the stock Laravel .htaccess (it strips trailing slashes), so the root is served as-is and
        // its relative links are made absolute instead.
        $rootBase = ($path === '' && $redirectRoot) ? rtrim($request->getPathInfo(), '/').'/' : null;

        $path = trim($path, '/');
        $path = $path === '' ? 'index.html' : $path;

        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
            abort(404);
        }

        $content = str_starts_with($path, 'assets/')
            ? $this->storage->readAsset($project, $path)
            : $this->storage->readVersionFile($version, $path);

        if ($content === null) {
            abort(404);
        }

        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TEXT, true)) {
            $content = $this->tokenizer->apply($content, $this->tokenizer->map((array) $project->brief));
        }

        if ($rootBase !== null && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            $content = $this->absolutizeLinks($content, $rootBase);
        }

        $headers = [
            'Content-Type' => $this->storage->mimeFor($path),
            'Content-Security-Policy' => "sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox; default-src 'none'; "
                ."script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                ."font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; media-src 'self' data: https:; "
                ."connect-src 'none'; form-action 'none'; base-uri 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($hosted) {
            $headers['Cache-Control'] = 'public, max-age='.(int) config('ai.sites.hosting.cache_seconds', 120);
        } else {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
            $headers['Cache-Control'] = 'no-store';
        }

        return new Response($content, 200, $headers);
    }

    /** Prefixes relative href/src/poster/action values with the site's base path ("#anchors", absolute URLs, mailto:, tel: are left alone). */
    protected function absolutizeLinks(string $html, string $base): string
    {
        return (string) preg_replace_callback(
            '/\b(href|src|poster|action)=(["\'])(.*?)\2/i',
            static function (array $m) use ($base): string {
                $value = $m[3];

                if ($value === '' || preg_match('~^(#|/|[a-z][a-z0-9+.-]*:|\{\{)~i', $value) === 1) {
                    return $m[0];
                }

                return $m[1].'='.$m[2].$base.ltrim($value, './').$m[2];
            },
            $html,
        );
    }
}
