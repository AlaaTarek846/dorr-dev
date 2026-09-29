<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatLinkPreview;
use Modules\Chat\Models\ChatMessage;
use Throwable;

/**
 * Link cards: the first URL in a message becomes a title + text + picture, read from the page's
 * Open Graph / Twitter / <title> tags. Fetched after the message is sent (never slows sending),
 * cached per URL for a day, and saved into the message's meta so every viewer sees the same card.
 *
 * The server fetches pages people paste, so it refuses anything that isn't a public web address
 * (no localhost, private or reserved IPs — also after a redirect), stops at 3 redirects and 512KB.
 */
class LinkPreviewService
{
    private const MAX_BYTES = 524_288;

    private const TTL_HOURS = 24;

    public function firstUrl(?string $text): ?string
    {
        if ($text === null || ! preg_match('~\b((?:https?://|www\.)[^\s<>"]+)~i', $text, $m)) {
            return null;
        }

        $url = rtrim($m[1], '.,;:!?)]}\'"،');

        return str_starts_with(strtolower($url), 'http') ? $url : 'https://'.$url;
    }

    /**
     * The card for a URL (cached), or null when the page has nothing to show or can't be reached.
     *
     * @return array<string, string|null>|null
     */
    public function forUrl(string $url): ?array
    {
        $url = trim($url);
        $hash = hash('sha256', $url);
        $row = ChatLinkPreview::query()->where('url_hash', $hash)->first();

        if ($row !== null && $row->fetched_at->gt(now()->subHours(self::TTL_HOURS))) {
            return $row->card();
        }

        $data = $this->fetch($url);

        $row = ChatLinkPreview::query()->updateOrCreate(['url_hash' => $hash], [
            'url' => $url,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'site_name' => $data['site_name'] ?? null,
            'failed' => $data === null || (($data['title'] ?? null) === null && ($data['image'] ?? null) === null),
            'fetched_at' => now(),
        ]);

        return $row->card();
    }

    /**
     * Put the card of the message's first link into its meta and tell everyone (message.updated).
     * Clears an old card when an edit removed the link.
     */
    public function attachTo(int $messageId): void
    {
        $message = ChatMessage::query()->find($messageId);

        if ($message === null || $message->isGone()) {
            return;
        }

        $url = $this->firstUrl($message->body);
        $meta = (array) ($message->meta ?? []);
        $card = $url !== null ? $this->forUrl($url) : null;

        if (($meta['link_preview'] ?? null) === $card) {
            return;
        }

        if ($card === null) {
            unset($meta['link_preview']);
        } else {
            $meta['link_preview'] = $card;
        }

        $message->forceFill(['meta' => $meta === [] ? null : $meta])->save();
        app(MessageService::class)->broadcastUpdate($message);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function fetch(string $url): ?array
    {
        try {
            for ($hop = 0; $hop <= 3; $hop++) {
                if (! $this->isPublic($url)) {
                    return null;
                }

                $response = Http::timeout(5)->connectTimeout(3)
                    ->withOptions(['allow_redirects' => false, 'stream' => false])
                    ->withHeaders([
                        // Many sites only serve their tags to bots that say who they are.
                        'User-Agent' => 'Mozilla/5.0 (compatible; DorrLinkPreview/1.0; +https://dorr.app)',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => app()->getLocale().',en;q=0.8',
                    ])
                    ->get($url);

                if ($response->redirect()) {
                    $next = $response->header('Location');
                    if ($next === '') {
                        return null;
                    }
                    $url = $this->absolute($next, $url);

                    continue;
                }

                if (! $response->successful()) {
                    return null;
                }

                // A picture link: the picture is the card.
                $type = strtolower($response->header('Content-Type'));
                if (str_starts_with($type, 'image/')) {
                    return ['title' => null, 'description' => null, 'image' => $url, 'site_name' => parse_url($url, PHP_URL_HOST) ?: null];
                }
                if ($type !== '' && ! str_contains($type, 'html')) {
                    return null;
                }

                return $this->parse(substr($response->body(), 0, self::MAX_BYTES), $url);
            }
        } catch (Throwable $e) {
            Log::info('chat link preview failed', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    private function parse(string $html, string $url): array
    {
        $tags = [];
        if (preg_match_all('~<meta\s+[^>]*>~i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $key = $this->attr($tag, 'property') ?? $this->attr($tag, 'name');
                $content = $this->attr($tag, 'content');
                if ($key !== null && $content !== null && ! isset($tags[strtolower($key)])) {
                    $tags[strtolower($key)] = $content;
                }
            }
        }

        $title = $tags['og:title'] ?? $tags['twitter:title'] ?? null;
        if ($title === null && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = $m[1];
        }

        $image = $tags['og:image'] ?? $tags['og:image:url'] ?? $tags['twitter:image'] ?? null;

        return [
            'title' => $this->clean($title, 300),
            'description' => $this->clean($tags['og:description'] ?? $tags['twitter:description'] ?? $tags['description'] ?? null, 500),
            'image' => $image !== null ? $this->absolute(html_entity_decode($image), $url) : null,
            'site_name' => $this->clean($tags['og:site_name'] ?? null, 150) ?? (parse_url($url, PHP_URL_HOST) ?: null),
        ];
    }

    private function attr(string $tag, string $name): ?string
    {
        return preg_match('~\b'.$name.'\s*=\s*(["\'])(.*?)\1~is', $tag, $m) ? $m[2] : null;
    }

    private function clean(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)) ?? '');

        return $text === '' ? null : Str::limit($text, $max - 3);
    }

    private function absolute(string $link, string $base): string
    {
        if (preg_match('~^https?://~i', $link)) {
            return $link;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($link, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$link;
        }

        if (str_starts_with($link, '/')) {
            return $origin.$link;
        }

        $dir = isset($parts['path']) ? preg_replace('~/[^/]*$~', '/', $parts['path']) : '/';

        return $origin.$dir.$link;
    }

    /**
     * Only public web addresses: http(s), and every IP the host resolves to must be public.
     */
    private function isPublic(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (! config('chat.link_preview_check_dns', true)) {
            return true;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
