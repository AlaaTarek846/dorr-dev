<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Exceptions\ChatException;
use Throwable;

/**
 * GIFs and animated stickers from Giphy — a ready library, searched in the person's language.
 * The API key stays on the server (`GIPHY_API_KEY`); the apps only ever see media URLs.
 *
 * A sent GIF / sticker is looked up again by its id on the server, so a message can only ever
 * point at real Giphy media (never at a URL the client made up).
 */
class GiphyService
{
    private const BASE = 'https://api.giphy.com/v1';

    public function enabled(): bool
    {
        return (string) config('chat.giphy.key') !== '';
    }

    /**
     * Trending (no query) or search results, normalised. `$kind`: gifs | stickers.
     *
     * @return array{items: list<array<string, mixed>>, next_offset: int|null}
     */
    public function browse(string $kind, ?string $query, int $offset = 0, int $limit = 24): array
    {
        if (! $this->enabled()) {
            return ['items' => [], 'next_offset' => null];
        }

        $kind = $kind === 'stickers' ? 'stickers' : 'gifs';
        $query = trim((string) $query);
        $locale = app()->getLocale();
        $key = 'chat.giphy.'.md5(implode('|', [$kind, $query, $offset, $limit, $locale]));

        return Cache::remember($key, now()->addMinutes(10), function () use ($kind, $query, $offset, $limit, $locale) {
            $response = $this->get("/{$kind}/".($query === '' ? 'trending' : 'search'), array_filter([
                'q' => $query !== '' ? $query : null,
                'limit' => $limit,
                'offset' => $offset,
                'rating' => config('chat.giphy.rating', 'pg-13'),
                'lang' => $locale,
                'bundle' => 'messaging_non_clips',
            ], fn ($v) => $v !== null));

            $items = collect($response['data'] ?? [])->map(fn (array $row) => $this->normalize($row, $kind))->filter()->values()->all();
            $total = (int) data_get($response, 'pagination.total_count', 0);
            $next = $offset + count($items);

            return ['items' => $items, 'next_offset' => $items !== [] && $next < min($total, 499) ? $next : null];
        });
    }

    /**
     * One GIF / sticker by its Giphy id — what a message stores (from the server, not the client).
     *
     * @return array<string, mixed>
     */
    public function find(string $id, string $kind): array
    {
        if (! $this->enabled() || ! preg_match('/^[A-Za-z0-9]{5,64}$/', $id)) {
            throw new ChatException('gif_not_found', 422);
        }

        $item = Cache::remember("chat.giphy.id.{$id}", now()->addDay(), function () use ($id, $kind) {
            $row = $this->get('/gifs/'.$id, [])['data'] ?? null;

            return is_array($row) ? $this->normalize($row, $kind === 'sticker' ? 'stickers' : 'gifs') : null;
        });

        return $item ?? throw new ChatException('gif_not_found', 422);
    }

    /**
     * The sizes the apps need: a small moving preview for the grid, a medium one for the bubble.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalize(array $row, string $kind): ?array
    {
        $images = (array) ($row['images'] ?? []);
        $bubble = $images['downsized_medium'] ?? $images['fixed_width'] ?? $images['original'] ?? null;
        $grid = $images['fixed_width_downsampled'] ?? $images['fixed_width_small'] ?? $images['fixed_width'] ?? $bubble;

        if (! is_array($bubble) || empty($bubble['url'])) {
            return null;
        }

        return [
            'source' => 'giphy',
            'id' => (string) $row['id'],
            'kind' => $kind === 'stickers' ? 'sticker' : 'gif',
            'title' => trim((string) ($row['title'] ?? '')) ?: null,
            'url' => $bubble['url'],
            'webp' => $bubble['webp'] ?? ($images['fixed_width']['webp'] ?? null),
            'mp4' => $images['fixed_width']['mp4'] ?? null,
            'preview' => $grid['webp'] ?? $grid['url'] ?? $bubble['url'],
            'width' => (int) ($bubble['width'] ?? 0),
            'height' => (int) ($bubble['height'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = Http::timeout(6)->connectTimeout(3)->acceptJson()
                ->get(self::BASE.$path, ['api_key' => config('chat.giphy.key')] + $query);

            return $response->successful() ? (array) $response->json() : [];
        } catch (Throwable $e) {
            Log::info('giphy request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
