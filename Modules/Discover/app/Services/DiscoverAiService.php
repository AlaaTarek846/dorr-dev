<?php

namespace Modules\Discover\Services;

use App\Models\Country;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Chat\Services\ChatAiService;
use Modules\Discover\Exceptions\DiscoverException;
use Modules\Discover\Models\DiscoverCategory;
use Modules\Discover\Models\DiscoverCity;

/**
 * "Something for the family in Dubai this weekend" (spec 181): DORR AI only turns the words into
 * filters — city, kinds, dates, free, family. The events come from Discover itself, so the AI can
 * never invent one, a time or a price.
 */
class DiscoverAiService
{
    public function __construct(
        private readonly ChatAiService $ai,
        private readonly DiscoverService $discover,
    ) {}

    /**
     * @return array{filters: array<string, mixed>, items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function ask(Model $me, string $text, ?Country $country, string $zone): array
    {
        if (! $this->discover->settings()->ai_enabled) {
            throw new DiscoverException('ai_off', 403);
        }

        $cities = DiscoverCity::query()->with('translations')->where('status', true)->get();
        $categories = DiscoverCategory::query()->with('translations')->where('status', true)->get();
        $today = CarbonImmutable::now($zone)->format('Y-m-d (l)');

        $answer = $this->ai->json($this->ai->ask([
            ['role' => 'system', 'content' => 'You turn a request for things to do into search filters. Today is '.$today.'. '
                .'Cities (id: names): '.$cities->map(fn (DiscoverCity $c) => $c->id.': '.$c->translations->pluck('name')->implode(' / '))->implode('; ').'. '
                .'Kinds (key: names): '.$categories->map(fn (DiscoverCategory $c) => $c->key.': '.$c->translations->pluck('name')->implode(' / '))->implode('; ').'. '
                .'Reply with JSON only: {"city_id": <id or null>, "categories": [<keys>], "from": "YYYY-MM-DD" or null, "to": "YYYY-MM-DD" or null, "free": true | false, "family": true | false, "q": "<one word to look for, or null>"}. '
                .'Only what the request says: resolve "this weekend" (Friday and Saturday), "tomorrow", "next week" from today. Never name or invent an event.'],
            ['role' => 'user', 'content' => Str::limit($text, 500)],
        ]));

        $filters = [];
        $cityId = (int) ($answer['city_id'] ?? 0);
        if ($cityId > 0 && $cities->contains('id', $cityId)) {
            $filters['city_id'] = $cityId;
        } elseif ($country !== null) {
            $filters['country_id'] = $country->id;
        }
        $ids = $categories->whereIn('key', array_filter((array) ($answer['categories'] ?? []), 'is_string'))->pluck('id')->values()->all();
        if ($ids !== []) {
            $filters['category_ids'] = $ids;
        }
        foreach (['from', 'to'] as $key) {
            if (is_string($answer[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer[$key])) {
                $filters[$key] = $answer[$key];
            }
        }
        if (! empty($answer['free'])) {
            $filters['free'] = true;
        }
        if (! empty($answer['family'])) {
            $filters['family'] = true;
        }
        if (is_string($answer['q'] ?? null) && trim($answer['q']) !== '') {
            $filters['q'] = Str::limit(trim($answer['q']), 60, '');
        }

        $result = $this->discover->search($me, $filters, $zone);
        // A word that matched nothing shouldn't hide what does match the rest.
        if ($result['items'] === [] && isset($filters['q'])) {
            unset($filters['q']);
            $result = $this->discover->search($me, $filters, $zone);
        }

        $city = isset($filters['city_id']) ? $cities->firstWhere('id', $filters['city_id']) : null;

        return [
            'filters' => $filters + [
                'city' => $city?->translatedName(),
                'categories' => $categories->whereIn('id', $filters['category_ids'] ?? [])->map(fn (DiscoverCategory $c) => $c->translatedName())->values()->all(),
            ],
        ] + $result;
    }
}
