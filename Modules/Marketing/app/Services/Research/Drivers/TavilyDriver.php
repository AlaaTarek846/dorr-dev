<?php

namespace Modules\Marketing\Services\Research\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Services\Research\Contracts\SearchDriver;
use Throwable;

/** Tavily search API (free tier, 1,000 credits a month): results come with a text extract made for LLMs. */
class TavilyDriver implements SearchDriver
{
    public function name(): string
    {
        return 'tavily';
    }

    public function ready(): bool
    {
        return (string) config('marketing.research.tavily.key') !== '';
    }

    public function search(string $query, int $maxResults): array
    {
        try {
            $response = Http::withToken((string) config('marketing.research.tavily.key'))
                ->acceptJson()->timeout((int) config('marketing.research.tavily.timeout', 20))
                ->post((string) config('marketing.research.tavily.url'), [
                    'query' => $query,
                    'search_depth' => (string) config('marketing.research.tavily.depth', 'basic'),
                    'max_results' => max(1, min(20, $maxResults)),
                    'include_answer' => false,
                    'include_raw_content' => false,
                ]);
        } catch (Throwable $e) {
            Log::warning('[Marketing research] tavily: '.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            Log::warning('[Marketing research] tavily '.$response->status());

            return [];
        }

        return collect($response->json('results') ?? [])
            ->filter(fn ($r) => is_array($r) && is_string($r['url'] ?? null) && preg_match('#^https?://#i', $r['url']))
            ->map(fn (array $r) => [
                'url' => (string) $r['url'],
                'title' => isset($r['title']) ? (string) $r['title'] : null,
                'content' => (string) ($r['content'] ?? ''),
                'published_at' => isset($r['published_date']) ? (string) $r['published_date'] : null,
                'score' => isset($r['score']) ? (float) $r['score'] : null,
            ])->values()->all();
    }
}
