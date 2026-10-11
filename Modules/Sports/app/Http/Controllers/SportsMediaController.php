<?php

namespace Modules\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sports\Support\SportsMedia;
use Throwable;

/**
 * GET media/{path} — a logo or a photo from our copy; the first time, it's copied from the
 * provider (a few per second at most — over that, the phone is sent to the provider itself).
 */
class SportsMediaController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'public, max-age=2592000, immutable'];

    public function show(string $path)
    {
        abort_unless(preg_match('#^'.SportsMedia::PATH.'$#', $path) === 1, 404);
        $file = SportsMedia::file($path);
        if (is_file($file)) {
            return response()->file($file, self::HEADERS + ['Content-Type' => $this->type($path)]);
        }
        $origin = SportsMedia::ORIGIN.$path;
        if (RateLimiter::tooManyAttempts('sports-media-copy', 8)) {
            return redirect()->away($origin);
        }
        RateLimiter::hit('sports-media-copy', 1);
        try {
            $r = Http::timeout(8)->get($origin);
        } catch (Throwable) {
            return redirect()->away($origin);
        }
        $type = strtolower((string) $r->header('Content-Type'));
        if (! $r->successful() || ! (str_starts_with($type, 'image/') || str_contains($type, 'svg'))) {
            abort(404);
        }
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $r->body());

        return response($r->body(), 200, self::HEADERS + ['Content-Type' => $this->type($path)]);
    }

    private function type(string $path): string
    {
        return str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png';
    }
}
