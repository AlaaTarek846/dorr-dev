<?php

namespace Modules\Discover\Services;

use App\Models\Country;
use App\Models\Region;
use App\Support\Api\ApiPaginator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Exceptions\DiscoverException;
use Modules\Discover\Models\DiscoverCategory;
use Modules\Discover\Models\DiscoverCity;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverFollow;
use Modules\Discover\Models\DiscoverInterest;
use Modules\Discover\Models\DiscoverPreference;
use Modules\Discover\Models\DiscoverSetting;

/**
 * DORR Discover for people (spec 169–173, 177): what's on in my city, in cities I follow and where
 * I'm travelling — only approved events from trusted sources, each in its own time zone and in mine.
 */
class DiscoverService
{
    public const PER_PAGE = 15;

    public const MAX_TRAVEL_DAYS = 31;

    public const MAX_FOLLOWS = 30;

    /** Regions come in batches of this many (the home strip loads more as it scrolls). */
    public const REGIONS_PAGE = 10;

    public function settings(): DiscoverSetting
    {
        return DiscoverSetting::current();
    }

    public function assertOn(?Country $country): void
    {
        if (! $this->settings()->onIn($country?->id)) {
            throw DiscoverException::off();
        }
    }

    public function zone(?string $zone, ?Model $me = null): string
    {
        foreach ([$zone, $me?->getAttribute('timezone')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    public function base(): Builder
    {
        return DiscoverEvent::query()->public()->with(['category.translations', 'city.translations', 'country.currency', 'organizer', 'media']);
    }

    public function assertPublic(DiscoverEvent $event): void
    {
        if ($event->review_status !== 'approved') {
            throw DiscoverException::notFound();
        }
    }

    // ------------------------------------------------------------------ browse & search (169–171, 173)

    /**
     * @param  array<string, mixed>  $f  city_id | country_id, category_ids, from, to (Y-m-d), free, family, q, near{lat,lng,km}, sort, page
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function search(Model $me, array $f, string $zone): array
    {
        $city = ! empty($f['city_id']) ? DiscoverCity::query()->find((int) $f['city_id']) : null;
        $query = $this->base()->upcoming();
        $this->filter($query, $f, $city, $zone);
        $page = $query->paginate(min((int) ($f['per_page'] ?? self::PER_PAGE), 50), ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        return ['items' => $this->presentMany($me, $page->getCollection(), $zone, $f['near'] ?? null), 'meta' => ApiPaginator::meta($page)];
    }

    /**
     * Travelling (AT-DISC-03): what's on in that city on my dates — the days cut in the city's own
     * zone, whatever country I'm in now.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function travel(Model $me, array $f, string $from, string $to, string $zone): array
    {
        $city = null;
        $region = null;
        if (! empty($f['city_id'])) {
            $city = DiscoverCity::query()->with(['translations', 'country'])->where('status', true)->find((int) $f['city_id']) ?? throw DiscoverException::notFound();
            $country = $city->country;
            $place = ['city_id' => $city->id];
            $placeZone = $city->timezone;
        } elseif (! empty($f['region_id'])) {
            $region = \App\Models\Region::query()->with('country')->find((int) $f['region_id']) ?? throw DiscoverException::notFound();
            $country = $region->country;
            $place = ['region_id' => $region->id];
            $placeZone = DiscoverCity::query()->where('status', true)->where('region_id', $region->id)->orderBy('sort_order')->value('timezone');
        } else {
            $country = Country::query()->find((int) $f['country_id']) ?? throw DiscoverException::notFound();
            $place = ['country_id' => $country->id];
            $placeZone = DiscoverCity::query()->where('status', true)->where('country_id', $country->id)->orderBy('sort_order')->value('timezone');
        }
        $this->assertOn($country);
        // The days are cut in the destination's own zone (a city's; else its first city's; else mine).
        $placeZone ??= $zone;
        $start = CarbonImmutable::parse($from, $placeZone);
        $end = CarbonImmutable::parse($to, $placeZone);
        if ($end->lt($start) || $start->diffInDays($end) > self::MAX_TRAVEL_DAYS) {
            throw new DiscoverException('travel_range', 422, ['max' => self::MAX_TRAVEL_DAYS]);
        }

        return [
            'city' => $city ? $this->presentCity($city) : null,
            'place' => ['country_id' => $country?->id, 'region_id' => $region?->id, 'timezone' => $placeZone],
            'from' => $from, 'to' => $to,
        ] + $this->search($me, $place + ['from' => $from, 'to' => $to] + array_intersect_key($f, array_flip(['category_ids', 'free', 'family', 'q', 'sort', 'page'])) + ['day_zone' => $placeZone], $zone);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function filter(Builder $q, array $f, ?DiscoverCity $city, string $zone): void
    {
        if ($city !== null) {
            $q->where('city_id', $city->id);
        } elseif (! empty($f['region_id'])) {
            // A region: the events of its cities.
            $q->whereIn('city_id', DiscoverCity::query()->where('region_id', (int) $f['region_id'])->select('id'));
        } elseif (! empty($f['country_id'])) {
            $q->where('country_id', (int) $f['country_id']);
        }
        if (! empty($f['category_ids'])) {
            $q->whereIn('category_id', array_map('intval', (array) $f['category_ids']));
        }
        // One city: its own days; several: mine.
        $dayZone = $city?->timezone ?? ($f['day_zone'] ?? $zone);
        $this->overlapping(
            $q,
            ! empty($f['from']) ? CarbonImmutable::parse($f['from'], $dayZone)->startOfDay() : null,
            ! empty($f['to']) ? CarbonImmutable::parse($f['to'], $dayZone)->endOfDay() : null,
        );
        if (! empty($f['free'])) {
            $q->where('is_free', true);
        }
        if (! empty($f['family'])) {
            $q->where('family_friendly', true);
        }
        if (! empty($f['q'])) {
            $term = '%'.trim((string) $f['q']).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('venue', 'like', $term)->orWhere('description', 'like', $term));
        }
        if (! empty($f['near']['lat']) && ! empty($f['near']['lng'])) {
            $lat = (float) $f['near']['lat'];
            $lng = (float) $f['near']['lng'];
            $km = min(200.0, max(1.0, (float) ($f['near']['km'] ?? 25)));
            $dLat = $km / 111;
            $dLng = $km / (111 * max(0.1, cos(deg2rad($lat))));
            $q->whereBetween('lat', [$lat - $dLat, $lat + $dLat])->whereBetween('lng', [$lng - $dLng, $lng + $dLng]);
        }
        if (($f['sort'] ?? 'soon') === 'popular') {
            $q->orderByDesc('interested_count');
        }
        $q->orderBy('starts_at')->orderBy('id');
    }

    private function overlapping(Builder $q, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($to !== null) {
            $q->where('starts_at', '<=', $to->utc());
        }
        if ($from !== null) {
            $q->where(fn ($w) => $w->where('starts_at', '>=', $from->utc())->orWhere('ends_at', '>=', $from->utc()));
        }
    }

    /**
     * Discover's home: for me (my interests), this week, the weekend, free, popular, and the cities
     * I follow elsewhere — empty sections left out.
     *
     * @return array<string, mixed>
     */
    public function home(Model $me, ?Country $country, string $zone): array
    {
        $sections = [];
        foreach (['for_you', 'this_week', 'weekend', 'free', 'popular', 'followed'] as $key) {
            $page = $this->sectionPage($me, $country, $zone, $key, 1, self::PER_PAGE);
            if ($page['items'] === []) {
                continue;
            }
            $sections[] = ['key' => $key, 'items' => $page['items'], 'has_more' => (bool) ($page['pagination']['has_more_pages'] ?? false)];
        }

        $settings = $this->settings();

        return [
            'country' => $country?->code,
            'categories' => $this->categories(),
            'cities' => $this->cities($country?->id),
            // The regions of the country I'm in that have something on — the first batch (more: GET regions).
            'regions' => $this->regions($country?->id, 1, self::REGIONS_PAGE),
            'follows' => $this->follows($me),
            'preferences' => $this->presentPreferences($this->preferences($me)),
            'can_submit' => (bool) $settings->submissions_enabled,
            'ai' => (bool) $settings->ai_enabled,
            'sections' => $sections,
        ];
    }

    /**
     * One home strip, 15 at a time — the app scrolls the row; it does not open a new page.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function sectionPage(Model $me, ?Country $country, string $zone, string $key, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $query = $this->sectionQuery($me, $country, $zone, $key);
        if ($query === null) {
            return [
                'items' => [],
                'pagination' => [
                    'per_page' => min(max(1, $perPage), 50),
                    'path' => '',
                    'total' => 0,
                    'current_page' => 1,
                    'next_page_url' => null,
                    'prev_page_url' => null,
                    'last_page' => 1,
                    'has_more_pages' => false,
                    'from' => null,
                    'to' => null,
                ],
            ];
        }
        $paginator = $query->paginate(min(max(1, $perPage), 50), ['*'], 'page', max(1, $page));

        return [
            'items' => $this->presentMany($me, $paginator->getCollection(), $zone),
            'pagination' => ApiPaginator::meta($paginator),
        ];
    }

    private function sectionQuery(Model $me, ?Country $country, string $zone, string $key): ?Builder
    {
        $prefs = $this->preferences($me);
        $now = CarbonImmutable::now($zone);
        $weekendStart = $now->isFriday() || $now->isSaturday() ? $now->startOfDay() : $now->next(CarbonImmutable::FRIDAY)->startOfDay();
        $weekendEnd = $weekendStart->isSaturday() ? $weekendStart->endOfDay() : $weekendStart->addDay()->endOfDay();
        $here = function (Builder $q) use ($country): Builder {
            return $country !== null ? $q->where('country_id', $country->id) : $q;
        };

        $q = $this->base()->upcoming();
        if ($prefs->family_only) {
            $q->where('family_friendly', true);
        }

        $popular = false;
        switch ($key) {
            case 'for_you':
                $ids = $prefs->categoryIds();
                if ($ids === []) {
                    return null;
                }
                $here($q)->whereIn('category_id', $ids);
                break;
            case 'this_week':
                $this->overlapping($here($q), $now, $now->addDays(7));
                break;
            case 'weekend':
                $this->overlapping($here($q), $weekendStart, $weekendEnd);
                break;
            case 'free':
                $here($q)->where('is_free', true);
                break;
            case 'popular':
                $here($q)->where('interested_count', '>', 0);
                $popular = true;
                break;
            case 'followed':
                $follows = DiscoverFollow::query()->ownedBy($me)->get();
                $cityIds = $follows->where('kind', 'city')->pluck('target_id')->all();
                $regionIds = $follows->where('kind', 'region')->pluck('target_id')->all();
                $countryIds = $follows->where('kind', 'country')->pluck('target_id')->all();
                $cityIds = array_values(array_unique([...$cityIds, ...($regionIds === [] ? [] : DiscoverCity::query()->whereIn('region_id', $regionIds)->pluck('id')->all())]));
                if ($cityIds === [] && $countryIds === []) {
                    return null;
                }
                $q->where(fn ($w) => $w->whereIn('city_id', $cityIds ?: [0])->orWhereIn('country_id', $countryIds ?: [0]))
                    ->when($country !== null, fn ($w) => $w->where('country_id', '!=', $country->id));
                break;
            default:
                throw DiscoverException::notFound();
        }

        $popular ? $q->orderByDesc('interested_count')->orderBy('starts_at') : $q->orderBy('starts_at');

        return $q;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Model $me, DiscoverEvent $event, string $zone): array
    {
        $event->loadMissing(['category.translations', 'city.translations', 'country.currency', 'organizer', 'media']);
        if ($event->review_status !== 'approved' && ! ($event->organizer?->isOwnedBy($me) ?? false)) {
            throw DiscoverException::notFound();
        }
        $interest = DiscoverInterest::query()->ownedBy($me)->where('event_id', $event->id)->first();

        return $this->presentFull($event, $zone, $interest, $me);
    }

    // ------------------------------------------------------------------ interested (177)

    public function interest(Model $me, DiscoverEvent $event, bool $notify): DiscoverInterest
    {
        $this->assertPublic($event);

        return DB::transaction(function () use ($me, $event, $notify) {
            $row = DiscoverInterest::query()->firstOrNew(['event_id' => $event->id, 'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()]);
            $new = ! $row->exists;
            $row->notify = $notify;
            $row->save();
            if ($new) {
                DiscoverEvent::query()->whereKey($event->id)->increment('interested_count');
            }

            return $row;
        });
    }

    public function uninterest(Model $me, DiscoverEvent $event): void
    {
        DB::transaction(function () use ($me, $event) {
            if (DiscoverInterest::query()->ownedBy($me)->where('event_id', $event->id)->delete() > 0) {
                DiscoverEvent::query()->whereKey($event->id)->where('interested_count', '>', 0)->decrement('interested_count');
            }
        });
    }

    /**
     * What I said "interested" to — coming ones first; `past` for those already over. 15 at a time.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function interests(Model $me, string $zone, bool $past = false, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $now = now();
        $ended = function ($q) use ($now) {
            $q->where(function ($x) use ($now) {
                $x->whereNotNull('discover_events.ends_at')->where('discover_events.ends_at', '<', $now);
            })->orWhere(function ($y) use ($now) {
                $y->whereNull('discover_events.ends_at')->where('discover_events.starts_at', '<', $now);
            });
        };

        $query = DiscoverInterest::query()->ownedBy($me)
            ->join('discover_events', 'discover_events.id', '=', 'discover_interests.event_id')
            ->where('discover_events.review_status', 'approved')
            ->when(
                $past,
                fn ($q) => $q->where($ended),
                fn ($q) => $q->where(function ($w) use ($now) {
                    $w->where(function ($x) use ($now) {
                        $x->whereNotNull('discover_events.ends_at')->where('discover_events.ends_at', '>=', $now);
                    })->orWhere(function ($y) use ($now) {
                        $y->whereNull('discover_events.ends_at')->where(function ($z) use ($now) {
                            $z->whereNull('discover_events.starts_at')->orWhere('discover_events.starts_at', '>=', $now);
                        });
                    });
                }),
            )
            ->with(['event' => fn ($q) => $q->with(['category.translations', 'city.translations', 'country.currency', 'organizer', 'media'])])
            ->select('discover_interests.*')
            ->orderBy('discover_events.starts_at', $past ? 'desc' : 'asc');

        $paginator = $query->paginate(min(max(1, $perPage), 50), ['discover_interests.*'], 'page', max(1, $page));

        return [
            'items' => $paginator->getCollection()
                ->filter(fn (DiscoverInterest $i) => $i->event !== null)
                ->map(fn (DiscoverInterest $i) => $this->present($i->event, $zone, $i))
                ->values()->all(),
            'pagination' => ApiPaginator::meta($paginator),
        ];
    }

    // ------------------------------------------------------------------ follows (170) & preferences (171, 172)

    /**
     * @return list<array<string, mixed>>
     */
    public function follows(Model $me): array
    {
        $rows = DiscoverFollow::query()->ownedBy($me)->orderBy('id')->get();
        $cities = DiscoverCity::query()->with(['translations', 'country'])->whereIn('id', $rows->where('kind', 'city')->pluck('target_id'))->get()->keyBy('id');
        $countries = Country::query()->with('translations')->whereIn('id', $rows->where('kind', 'country')->pluck('target_id'))->get()->keyBy('id');
        $regions = Region::query()->with(['translations', 'country'])->whereIn('id', $rows->where('kind', 'region')->pluck('target_id'))->get()->keyBy('id');

        return $rows->map(function (DiscoverFollow $f) use ($cities, $countries, $regions) {
            $city = $f->kind === 'city' ? $cities->get($f->target_id) : null;
            $region = $f->kind === 'region' ? $regions->get($f->target_id) : null;
            $country = match ($f->kind) {
                'country' => $countries->get($f->target_id),
                'region' => $region?->country,
                default => $city?->country,
            };

            return [
                'id' => $f->id,
                'kind' => $f->kind,
                'target_id' => (int) $f->target_id,
                'name' => match ($f->kind) {
                    'city' => $city?->translatedName(),
                    'region' => $region?->translatedName(),
                    default => $country?->translatedName(),
                },
                'country' => $country?->code,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function follow(Model $me, string $kind, int $targetId): array
    {
        $exists = match ($kind) {
            'city' => DiscoverCity::query()->where('status', true)->whereKey($targetId)->exists(),
            'region' => Region::query()->where('status', true)->whereKey($targetId)->exists(),
            default => Country::query()->whereKey($targetId)->exists(),
        };
        if (! $exists) {
            throw DiscoverException::notFound();
        }
        $owner = ['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()];
        if (! DiscoverFollow::query()->where($owner + ['kind' => $kind, 'target_id' => $targetId])->exists()
            && DiscoverFollow::query()->where($owner)->count() >= self::MAX_FOLLOWS) {
            throw new DiscoverException('too_many_follows', 422, ['max' => self::MAX_FOLLOWS]);
        }
        DiscoverFollow::query()->firstOrCreate($owner + ['kind' => $kind, 'target_id' => $targetId]);

        return $this->follows($me);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unfollow(Model $me, int $id): array
    {
        DiscoverFollow::query()->ownedBy($me)->whereKey($id)->delete();

        return $this->follows($me);
    }

    public function preferences(Model $me): DiscoverPreference
    {
        return DiscoverPreference::query()->firstOrNew(['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function savePreferences(Model $me, array $data): array
    {
        $prefs = $this->preferences($me);
        if (array_key_exists('categories', $data)) {
            $prefs->categories = array_values(array_unique(array_map('intval', $data['categories'] ?? [])));
        }
        foreach (['alerts', 'alert_days', 'family_only'] as $key) {
            if (array_key_exists($key, $data)) {
                $prefs->{$key} = $data[$key];
            }
        }
        $prefs->save();

        return $this->presentPreferences($prefs);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPreferences(DiscoverPreference $prefs): array
    {
        return [
            'categories' => $prefs->categoryIds(),
            'alerts' => (bool) $prefs->alerts,
            'alert_days' => (int) $prefs->alert_days,
            'family_only' => (bool) $prefs->family_only,
        ];
    }

    // ------------------------------------------------------------------ catalogs

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->categoryQuery()->get()->map(fn (DiscoverCategory $c) => $this->presentCategory($c))->values()->all();
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function categoriesPage(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $paginator = $this->categoryQuery()->paginate(min(max(1, $perPage), 50), ['*'], 'page', max(1, $page));

        return [
            'items' => $paginator->getCollection()->map(fn (DiscoverCategory $c) => $this->presentCategory($c))->values()->all(),
            'pagination' => ApiPaginator::meta($paginator),
        ];
    }

    private function categoryQuery(): Builder
    {
        return DiscoverCategory::query()->with('translations')->where('status', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCategory(DiscoverCategory $c): array
    {
        return ['id' => $c->id, 'key' => $c->key, 'name' => $c->translatedName(), 'emoji' => $c->emoji, 'color' => $c->color];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cities(?int $countryId = null, ?int $regionId = null, bool $withEvents = false): array
    {
        return DiscoverCity::query()->with(['translations', 'country.currency'])->where('status', true)
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))
            ->when($withEvents, fn ($q) => $q->whereIn('id', $this->upcomingCityIds()))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCity $c) => $this->presentCity($c))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCity(DiscoverCity $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->translatedName(),
            'country' => $c->country?->code,
            'country_id' => $c->country_id,
            'region_id' => $c->region_id,
            'timezone' => $c->timezone,
            'lat' => $c->lat,
            'lng' => $c->lng,
            'currency' => $this->presentCurrency($c->country?->currency),
        ];
    }

    /**
     * A country's active regions (the dashboard catalog), in the admin's order, a page at a time —
     * with how many upcoming public events each has. `withEvents` (the home strip) keeps only
     * regions that have something on.
     *
     * @return array{items: list<array{id: int, name: ?string, country_id: int, events_count: int}>, meta: array<string, mixed>}
     */
    public function regions(?int $countryId, int $page = 1, int $perPage = self::REGIONS_PAGE, bool $withEvents = true): array
    {
        // Events per city, then cities → their region (no join: the scopes' columns stay unambiguous).
        $perCity = DiscoverEvent::query()->public()->upcoming()
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->groupBy('city_id')->selectRaw('city_id, count(*) as total')->pluck('total', 'city_id');
        $counts = DiscoverCity::query()->whereIn('id', $perCity->keys()->all() ?: [0])->whereNotNull('region_id')->pluck('region_id', 'id')
            ->groupBy(fn ($regionId) => $regionId, true)
            ->map(fn ($cities) => $cities->keys()->sum(fn ($cityId) => (int) $perCity[$cityId]));

        $paginator = Region::query()->with('translations')->where('status', true)
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->when($withEvents, fn ($q) => $q->whereIn('id', $counts->keys()->all() ?: [0]))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(min(max(1, $perPage), 50), ['*'], 'page', max(1, $page));

        return [
            'items' => $paginator->getCollection()->map(fn (Region $r) => [
                'id' => $r->id, 'name' => $r->translatedName(), 'country_id' => $r->country_id, 'events_count' => (int) ($counts[$r->id] ?? 0),
            ])->values()->all(),
            'meta' => ApiPaginator::meta($paginator),
        ];
    }

    /**
     * The countries catalog (`countries`) — the first step of the place filter.
     *
     * @return list<array{id: int, name: ?string, code: ?string}>
     */
    public function countries(): array
    {
        return Country::query()->with('translations')->where('status', true)
            ->orderByDesc('is_default')->orderBy('id')
            ->get()
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->translatedName() ?: $c->code, 'code' => $c->code])
            ->values()->all();
    }

    /** The cities with an upcoming public event (a subquery). */
    private function upcomingCityIds(): Builder
    {
        return DiscoverEvent::query()->public()->upcoming()->select('city_id');
    }

    // ------------------------------------------------------------------ presenting

    /**
     * @param  iterable<DiscoverEvent>  $events
     * @param  array<string, mixed>|null  $near
     * @return list<array<string, mixed>>
     */
    public function presentMany(Model $me, iterable $events, string $zone, ?array $near = null): array
    {
        $events = collect($events);
        if ($events->isEmpty()) {
            return [];
        }
        $mine = DiscoverInterest::query()->ownedBy($me)->whereIn('event_id', $events->pluck('id'))->get()->keyBy('event_id');

        return $events->map(fn (DiscoverEvent $e) => $this->present($e, $zone, $mine->get($e->id), $near))->values()->all();
    }

    /**
     * One event as a list shows it: its own local time where it happens ("20:00 in Dubai") and mine
     * when I'm somewhere else now (169, 173).
     *
     * @param  array<string, mixed>|null  $near
     * @return array<string, mixed>
     */
    public function present(DiscoverEvent $e, string $zone, ?DiscoverInterest $interest = null, ?array $near = null): array
    {
        $tz = $e->timezone;
        $start = $e->starts_at ? CarbonImmutable::instance($e->starts_at) : null;
        $end = $e->ends_at ? CarbonImmutable::instance($e->ends_at) : null;

        $row = [
            'id' => $e->uuid,
            'title' => $e->title,
            'category' => $e->category ? $this->presentCategory($e->category) : null,
            'city' => $e->city ? ['id' => $e->city->id, 'name' => $e->city->translatedName(), 'timezone' => $e->city->timezone] : null,
            'country' => $e->country?->code,
            'venue' => $e->venue,
            'address' => $e->address,
            'lat' => $e->lat,
            'lng' => $e->lng,
            'starts_at' => $start?->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'timezone' => $tz,
            'local_date' => $start?->setTimezone($tz)->toDateString(),
            'local_time' => $start?->setTimezone($tz)->format('H:i'),
            'local_end' => $end?->setTimezone($tz)->format('Y-m-d H:i'),
            'my_time' => $start !== null && $tz !== $zone ? $start->setTimezone($zone)->format('Y-m-d H:i') : null,
            'is_free' => (bool) $e->is_free,
            'price_text' => $e->price_text,
            'price_display' => $this->presentPrice($e),
            'currency' => $this->presentCurrency($e->country?->currency),
            'family_friendly' => (bool) $e->family_friendly,
            'status' => $e->status,
            // Never "verified" just because it's listed (AT-DISC-02).
            'verified' => $e->isVerified(),
            'organizer' => $e->organizer ? ['name' => $e->organizer->name, 'verified' => $e->organizer->isVerified()] : null,
            'cover' => $e->coverUrl(),
            'interested_count' => (int) $e->interested_count,
            'interested' => $interest !== null,
            'notify' => (bool) $interest?->notify,
        ];

        if (! empty($near['lat']) && $e->lat !== null && $e->lng !== null) {
            $row['distance_km'] = round($this->distance((float) $near['lat'], (float) $near['lng'], $e->lat, $e->lng), 1);
        }

        return $row;
    }

    /**
     * Everything about one event: description, the official source and booking links, when it was
     * last checked, and its history (175).
     *
     * @return array<string, mixed>
     */
    public function presentFull(DiscoverEvent $e, string $zone, ?DiscoverInterest $interest = null, ?Model $me = null): array
    {
        $history = $e->history()->latest('id')->limit(5)->get();
        $last = $history->first();
        $mine = $me !== null && ($e->organizer?->isOwnedBy($me) ?? false);

        return $this->present($e, $zone, $interest) + [
            'description' => $e->description,
            'language' => $e->language,
            'source' => $e->source,
            'source_url' => $e->source_url,
            'booking_url' => $e->booking_url,
            'last_verified_at' => $e->last_verified_at?->toIso8601String(),
            'status_note' => $last?->note,
            'old_starts_at' => $last?->old_starts_at?->toIso8601String(),
            'history' => $history->map(fn ($h) => [
                'from' => $h->from_status, 'to' => $h->to_status, 'note' => $h->note,
                'old_starts_at' => $h->old_starts_at?->toIso8601String(), 'at' => $h->created_at?->toIso8601String(),
            ])->values()->all(),
            'mine' => $mine,
            'review_status' => $mine ? $e->review_status : null,
            'review_note' => $mine ? $e->review_note : null,
        ];
    }

    /**
     * Catalog currency of the event's country (`code`, `symbol`, decimals).
     *
     * @return array{code: string, symbol: ?string, decimal_places: int}|null
     */
    private function presentCurrency(?\App\Models\Currency $currency): ?array
    {
        if ($currency === null) {
            return null;
        }

        return [
            'code' => $currency->code,
            'symbol' => $currency->symbol ?: $currency->code,
            'decimal_places' => (int) $currency->decimal_places,
        ];
    }

    /** Free stays a label in the app; a number gets the country's symbol ("300 ج.م"), other text is left as typed. */
    private function presentPrice(DiscoverEvent $e): ?string
    {
        if ($e->is_free) {
            return null;
        }
        $raw = trim((string) $e->price_text);
        if ($raw === '') {
            return null;
        }
        $currency = $this->presentCurrency($e->country?->currency);
        $symbol = $currency['symbol'] ?? null;
        $code = $currency['code'] ?? null;
        $places = (int) ($currency['decimal_places'] ?? 2);
        $compact = str_replace(["\u{00A0}", ' ', ','], '', $raw);
        if (preg_match('/^\d+(?:\.\d+)?$/', $compact) === 1) {
            $n = (float) $compact;
            $formatted = abs($n - round($n)) < 0.00001
                ? number_format($n, 0, '.', ',')
                : number_format($n, $places, '.', ',');

            return $symbol ? $formatted.' '.$symbol : $formatted;
        }
        if ($symbol !== null && (str_contains($raw, $symbol) || ($code !== null && str_contains(mb_strtoupper($raw), mb_strtoupper($code))))) {
            return $raw;
        }

        return $symbol ? $raw.' '.$symbol : $raw;
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
