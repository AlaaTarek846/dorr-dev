<?php

namespace Modules\Discover\Services;

use App\Models\Country;
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
    public const PER_PAGE = 20;

    public const MAX_TRAVEL_DAYS = 31;

    public const MAX_FOLLOWS = 30;

    private const SECTION_SIZE = 10;

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
        return DiscoverEvent::query()->public()->with(['category.translations', 'city.translations', 'country', 'organizer', 'media']);
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
    public function travel(Model $me, int $cityId, string $from, string $to, array $f, string $zone): array
    {
        $city = DiscoverCity::query()->with(['translations', 'country'])->where('status', true)->find($cityId) ?? throw DiscoverException::notFound();
        $this->assertOn($city->country);
        $start = CarbonImmutable::parse($from, $city->timezone);
        $end = CarbonImmutable::parse($to, $city->timezone);
        if ($end->lt($start) || $start->diffInDays($end) > self::MAX_TRAVEL_DAYS) {
            throw new DiscoverException('travel_range', 422, ['max' => self::MAX_TRAVEL_DAYS]);
        }

        return ['city' => $this->presentCity($city), 'from' => $from, 'to' => $to]
            + $this->search($me, ['city_id' => $city->id, 'from' => $from, 'to' => $to] + array_intersect_key($f, array_flip(['category_ids', 'free', 'family', 'q', 'sort', 'page'])), $zone);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function filter(Builder $q, array $f, ?DiscoverCity $city, string $zone): void
    {
        if ($city !== null) {
            $q->where('city_id', $city->id);
        } elseif (! empty($f['country_id'])) {
            $q->where('country_id', (int) $f['country_id']);
        }
        if (! empty($f['category_ids'])) {
            $q->whereIn('category_id', array_map('intval', (array) $f['category_ids']));
        }
        // One city: its own days; several: mine.
        $dayZone = $city?->timezone ?? $zone;
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
        $prefs = $this->preferences($me);
        $follows = DiscoverFollow::query()->ownedBy($me)->get();
        $now = CarbonImmutable::now($zone);
        $weekendStart = $now->isFriday() || $now->isSaturday() ? $now->startOfDay() : $now->next(CarbonImmutable::FRIDAY)->startOfDay();
        $weekendEnd = $weekendStart->isSaturday() ? $weekendStart->endOfDay() : $weekendStart->addDay()->endOfDay();

        $here = fn (Builder $q) => $country !== null ? $q->where('country_id', $country->id) : $q;
        $section = function (string $key, callable $scope, bool $popular = false) use ($me, $zone, $prefs) {
            $q = $this->base()->upcoming();
            $scope($q);
            if ($prefs->family_only) {
                $q->where('family_friendly', true);
            }
            $popular ? $q->orderByDesc('interested_count')->orderBy('starts_at') : $q->orderBy('starts_at');
            $items = $this->presentMany($me, $q->limit(self::SECTION_SIZE)->get(), $zone);

            return $items === [] ? null : ['key' => $key, 'items' => $items];
        };

        $cityIds = $follows->where('kind', 'city')->pluck('target_id')->all();
        $countryIds = $follows->where('kind', 'country')->pluck('target_id')->all();

        $sections = [
            $prefs->categoryIds() !== [] ? $section('for_you', fn ($q) => $here($q)->whereIn('category_id', $prefs->categoryIds())) : null,
            $section('this_week', fn ($q) => $this->overlapping($here($q), $now, $now->addDays(7))),
            $section('weekend', fn ($q) => $this->overlapping($here($q), $weekendStart, $weekendEnd)),
            $section('free', fn ($q) => $here($q)->where('is_free', true)),
            $section('popular', fn ($q) => $here($q)->where('interested_count', '>', 0), true),
            $cityIds !== [] || $countryIds !== [] ? $section('followed', fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('city_id', $cityIds ?: [0])->orWhereIn('country_id', $countryIds ?: [0]))
                ->when($country !== null, fn ($w) => $w->where('country_id', '!=', $country->id))) : null,
        ];

        $settings = $this->settings();

        return [
            'country' => $country?->code,
            'categories' => $this->categories(),
            'cities' => $this->cities($country?->id),
            'follows' => $this->follows($me),
            'preferences' => $this->presentPreferences($prefs),
            'can_submit' => (bool) $settings->submissions_enabled,
            'ai' => (bool) $settings->ai_enabled,
            'sections' => array_values(array_filter($sections)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Model $me, DiscoverEvent $event, string $zone): array
    {
        $event->loadMissing(['category.translations', 'city.translations', 'country', 'organizer', 'media']);
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
     * What I said "interested" to — coming ones first; `past` for those already over.
     *
     * @return list<array<string, mixed>>
     */
    public function interests(Model $me, string $zone, bool $past = false): array
    {
        $now = now();

        return DiscoverInterest::query()->ownedBy($me)
            ->with(['event' => fn ($q) => $q->with(['category.translations', 'city.translations', 'country', 'organizer', 'media'])])
            ->get()
            ->filter(fn (DiscoverInterest $i) => $i->event !== null && $i->event->review_status === 'approved'
                && (($i->event->ends_at ?? $i->event->starts_at)?->lt($now) ?? false) === $past)
            ->sortBy(fn (DiscoverInterest $i) => ($past ? -1 : 1) * ($i->event->starts_at?->getTimestamp() ?? 0))
            ->map(fn (DiscoverInterest $i) => $this->present($i->event, $zone, $i))
            ->values()->all();
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

        return $rows->map(function (DiscoverFollow $f) use ($cities, $countries) {
            $city = $f->kind === 'city' ? $cities->get($f->target_id) : null;
            $country = $f->kind === 'country' ? $countries->get($f->target_id) : $city?->country;

            return [
                'id' => $f->id,
                'kind' => $f->kind,
                'target_id' => (int) $f->target_id,
                'name' => $f->kind === 'city' ? $city?->translatedName() : $country?->translatedName(),
                'country' => $country?->code,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function follow(Model $me, string $kind, int $targetId): array
    {
        $exists = $kind === 'city'
            ? DiscoverCity::query()->where('status', true)->whereKey($targetId)->exists()
            : Country::query()->whereKey($targetId)->exists();
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
        return DiscoverCategory::query()->with('translations')->where('status', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCategory $c) => $this->presentCategory($c))->values()->all();
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
    public function cities(?int $countryId = null): array
    {
        return DiscoverCity::query()->with(['translations', 'country'])->where('status', true)
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCity $c) => $this->presentCity($c))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCity(DiscoverCity $c): array
    {
        return ['id' => $c->id, 'name' => $c->translatedName(), 'country' => $c->country?->code, 'country_id' => $c->country_id, 'timezone' => $c->timezone, 'lat' => $c->lat, 'lng' => $c->lng];
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

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
