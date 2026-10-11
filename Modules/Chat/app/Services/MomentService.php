<?php

namespace Modules\Chat\Services;

use App\Models\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use IntlCalendar;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatMomentDate;
use Modules\Chat\Models\ChatMomentPreference;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * DORR Moments (spec 157–160, 168): when each occasion falls, which ones I see, and my own dates.
 *
 * Dates, in this order: the admin's date for that year and country, then the admin's date for that
 * year everywhere, then the rule — a fixed Gregorian day, or a Hijri day converted with the Umm
 * al-Qura calendar ("manual" moments have only the admin's dates). A correction is read at once,
 * so a moon sighting that differs reaches every phone without an app update (AT-MOM-02).
 */
class MomentService
{
    /** Looks for an occurrence this far ahead. */
    private const HORIZON_DAYS = 400;

    // ------------------------------------------------------------------ dates

    /**
     * The days this moment starts on during a Gregorian year (a Hijri one can fall twice in a year).
     *
     * @return list<CarbonImmutable>
     */
    public function datesIn(ChatMoment $moment, ?Country $country, int $year): array
    {
        $overrides = ChatMomentDate::query()->where('chat_moment_id', $moment->id)->where('year', $year)
            ->where(fn ($q) => $q->whereNull('country_id')->when($country, fn ($q, $c) => $q->orWhere('country_id', $c->id)))
            ->get();
        $override = ($country ? $overrides->firstWhere('country_id', $country->id) : null) ?? $overrides->firstWhere('country_id', null);
        if ($override !== null) {
            return [CarbonImmutable::parse($override->date->toDateString())];
        }

        return match ($moment->date_rule) {
            'gregorian' => $moment->month && $moment->day && checkdate($moment->month, $moment->day, $year)
                ? [CarbonImmutable::create($year, $moment->month, $moment->day)] : [],
            'hijri' => $this->hijriDatesIn($moment->month, $moment->day, $year),
            default => [],
        };
    }

    /**
     * The coming (or running) occurrence: `[start, end, visible_from, visible_until]`, or null.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, from: CarbonImmutable, until: CarbonImmutable}|null
     */
    public function occurrence(ChatMoment $moment, ?Country $country, ?CarbonInterface $today = null): ?array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        foreach ([$today->year - 1, $today->year, $today->year + 1] as $year) {
            foreach ($this->datesIn($moment, $country, $year) as $start) {
                $end = $start->addDays(max(1, $moment->duration_days) - 1);
                $until = $end->addDays($moment->show_after_days);
                if ($until->gte($today) && $start->lte($today->addDays(self::HORIZON_DAYS))) {
                    return ['start' => $start, 'end' => $end, 'from' => $start->subDays($moment->show_before_days), 'until' => $until];
                }
            }
        }

        return null;
    }

    /**
     * Umm al-Qura: the Gregorian days a Hijri month/day falls on within a Gregorian year.
     *
     * @return list<CarbonImmutable>
     */
    public function hijriDatesIn(?int $month, ?int $day, int $year): array
    {
        if (! $month || ! $day) {
            return [];
        }

        $cal = IntlCalendar::createInstance('UTC', 'en_US@calendar=islamic-umalqura');
        $cal->setTime(CarbonImmutable::create($year, 1, 1)->getTimestamp() * 1000);
        $hijriYear = $cal->get(IntlCalendar::FIELD_EXTENDED_YEAR);
        $out = [];

        foreach ([$hijriYear, $hijriYear + 1] as $hy) {
            $cal->clear();
            $cal->set(IntlCalendar::FIELD_EXTENDED_YEAR, $hy);
            $cal->set(IntlCalendar::FIELD_MONTH, $month - 1);
            $cal->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);
            $date = CarbonImmutable::createFromTimestampUTC((int) ($cal->getTime() / 1000))->startOfDay();
            if ($date->year === $year) {
                $out[] = $date;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ me

    public function preferences(Model $me): ChatMomentPreference
    {
        return ChatMomentPreference::query()->firstOrNew(['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()], ['enabled' => true, 'effects' => 'full']);
    }

    /** Whose occasions: the country I chose, else the one I signed in from, else my phone's. */
    public function countryFor(Model $me, ?ChatMomentPreference $prefs = null): ?Country
    {
        $prefs ??= $this->preferences($me);
        $id = $prefs->country_id ?? ($me->logged_in_country_id ?? null) ?? ($me->country_id ?? null);

        return $id ? Country::query()->find($id) : Country::query()->where('is_default', true)->first();
    }

    /**
     * The occasions I see: the ones of my country that are on by default (minus the ones I turned
     * off), plus the ones I picked.
     *
     * @return Collection<int, ChatMoment>
     */
    public function mine(Model $me, ChatMomentPreference $prefs, ?Country $country): Collection
    {
        $picked = array_map('intval', $prefs->picked ?? []);
        $muted = array_map('intval', $prefs->muted ?? []);

        return ChatMoment::query()->active()->with(['translations', 'media'])->orderBy('sort_order')->get()
            ->filter(fn (ChatMoment $m) => in_array($m->id, $picked, true) || ($m->default_on && $m->isIn($country?->code) && ! in_array($m->id, $muted, true)))
            ->values();
    }

    /**
     * The Moments Center (spec 160): what's on now (for the home page's banner), what's coming, and
     * my own dates — or `enabled: false` (switched off by me or the admin; the chat goes on, AT-MOM-01).
     *
     * @return array<string, mixed>
     */
    public function center(Model $me, int $days = 120): array
    {
        $prefs = $this->preferences($me);
        $country = $this->countryFor($me, $prefs);
        $on = (ChatSetting::current()->moments_enabled ?? true) && $prefs->enabled;
        $base = [
            'enabled' => $on,
            'effects' => $prefs->effects ?: 'full',
            'country' => $country ? ['id' => $country->id, 'code' => $country->code, 'name' => $country->translatedName()] : null,
        ];

        if (! $on) {
            return $base + ['active' => [], 'upcoming' => [], 'personal' => []];
        }

        $today = CarbonImmutable::parse(now()->toDateString());
        $key = 'chat:moments:'.($country?->id ?? 0).':'.$today->toDateString().':'.app()->getLocale().':'.ChatMoment::query()->max('updated_at').':'.ChatMomentDate::query()->max('updated_at');
        $all = Cache::remember($key, now()->addHours(6), fn () => ChatMoment::query()->active()->get()
            ->mapWithKeys(fn (ChatMoment $m) => [$m->id => $this->occurrence($m, $country, $today)])->all());

        $items = $this->mine($me, $prefs, $country)
            ->map(fn (ChatMoment $m) => ($o = $all[$m->id] ?? null) ? $this->present($m, $o, $today) : null)
            ->filter()
            ->filter(fn ($row) => $row['days_left'] <= $days)
            ->sortBy('start')->values();

        return $base + [
            'active' => $items->where('is_visible', true)->values()->all(),
            'upcoming' => $items->all(),
            'personal' => $this->personal($me, $today),
        ];
    }

    /**
     * Every occasion of a country, with whether I see it — for the preferences page.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(Model $me, ?Country $country): array
    {
        $prefs = $this->preferences($me);
        $country ??= $this->countryFor($me, $prefs);
        $picked = array_map('intval', $prefs->picked ?? []);
        $muted = array_map('intval', $prefs->muted ?? []);
        $today = CarbonImmutable::parse(now()->toDateString());

        return ChatMoment::query()->active()->with(['translations', 'media'])->orderBy('sort_order')->get()
            ->filter(fn (ChatMoment $m) => $m->isIn($country?->code) || in_array($m->id, $picked, true))
            ->map(function (ChatMoment $m) use ($country, $picked, $muted, $today) {
                $o = $this->occurrence($m, $country, $today);

                return [
                    'id' => $m->id,
                    'key' => $m->key,
                    'name' => $m->translatedName(),
                    'kind' => $m->kind,
                    'emoji' => $m->emoji,
                    'next' => $o ? $o['start']->toDateString() : null,
                    'default_on' => $m->default_on,
                    'on' => in_array($m->id, $picked, true) || ($m->default_on && ! in_array($m->id, $muted, true)),
                ];
            })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data  enabled, country_id, effects, on[] / off[] (moment ids)
     */
    public function savePreferences(Model $me, array $data): ChatMomentPreference
    {
        $prefs = $this->preferences($me);
        $prefs->fill(collect($data)->only(['enabled', 'country_id', 'effects'])->all());

        $picked = collect($prefs->picked ?? [])->map(fn ($v) => (int) $v);
        $muted = collect($prefs->muted ?? [])->map(fn ($v) => (int) $v);
        $defaults = ChatMoment::query()->whereIn('id', [...($data['on'] ?? []), ...($data['off'] ?? [])])->pluck('default_on', 'id');

        foreach ($data['on'] ?? [] as $id) {
            $muted = $muted->reject(fn ($v) => $v === (int) $id);
            if (! ($defaults[$id] ?? true)) {
                $picked->push((int) $id);
            }
        }
        foreach ($data['off'] ?? [] as $id) {
            $picked = $picked->reject(fn ($v) => $v === (int) $id);
            if ($defaults[$id] ?? false) {
                $muted->push((int) $id);
            }
        }

        $prefs->picked = $picked->unique()->values()->all();
        $prefs->muted = $muted->unique()->values()->all();
        $prefs->save();

        return $prefs->refresh();
    }

    // ------------------------------------------------------------------ my own dates

    /**
     * @return list<array<string, mixed>>
     */
    public function personal(Model $me, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::parse(now()->toDateString());
        $rows = ChatPersonalMoment::query()->ownedBy($me)->get();
        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->filter(fn ($r) => $r->contact_id)->map(fn ($r) => [$r->contact_type, $r->contact_id]));

        return $rows->map(function (ChatPersonalMoment $p) use ($today, $directory, $me) {
            $next = $this->nextYearly($p->month, $p->day, $today);

            return [
                'id' => $p->uuid,
                'kind' => $p->kind,
                'title' => $p->title,
                'month' => $p->month,
                'day' => $p->day,
                'year' => $p->year,
                'next' => $next->toDateString(),
                'days_left' => (int) $today->diffInDays($next),
                'turns' => $p->year ? $next->year - $p->year : null,
                'contact' => $p->contact_id ? $directory->profile($me, $p->contact_type, $p->contact_id) : null,
                'remind_days_before' => $p->remind_days_before,
                'theme' => $p->kind,
                'look' => self::PERSONAL_LOOKS[$p->kind] ?? self::PERSONAL_LOOKS['other'],
            ];
        })->sortBy('days_left')->values()->all();
    }

    /** The colours / emoji / animation of my own dates, by kind. */
    public const PERSONAL_LOOKS = [
        'birthday' => ['primary_color' => '#EC4899', 'secondary_color' => '#8B5CF6', 'emoji' => '🎂', 'animation' => 'balloons'],
        'anniversary' => ['primary_color' => '#BE123C', 'secondary_color' => '#FDA4AF', 'emoji' => '💍', 'animation' => 'hearts'],
        'graduation' => ['primary_color' => '#1E3A8A', 'secondary_color' => '#FBBF24', 'emoji' => '🎓', 'animation' => 'confetti'],
        'wedding' => ['primary_color' => '#9D174D', 'secondary_color' => '#FBCFE8', 'emoji' => '💒', 'animation' => 'petals'],
        'baby' => ['primary_color' => '#0EA5E9', 'secondary_color' => '#FBCFE8', 'emoji' => '👶', 'animation' => 'balloons'],
        'other' => ['primary_color' => '#001B53', 'secondary_color' => '#FA7552', 'emoji' => '✨', 'animation' => 'confetti'],
    ];

    public function nextYearly(int $month, int $day, CarbonImmutable $today): CarbonImmutable
    {
        foreach ([$today->year, $today->year + 1, $today->year + 2, $today->year + 3, $today->year + 4] as $y) {
            // 29 February: the 28th in other years.
            $d = checkdate($month, $day, $y) ? CarbonImmutable::create($y, $month, $day) : CarbonImmutable::create($y, $month, 28);
            if ($d->gte($today)) {
                return $d;
            }
        }

        return $today;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: CarbonImmutable, until: CarbonImmutable}  $o
     * @return array<string, mixed>
     */
    public function present(ChatMoment $m, array $o, CarbonImmutable $today): array
    {
        return [
            'id' => $m->id,
            'key' => $m->key,
            'name' => $m->translatedName(),
            'greeting' => $m->translated('greeting'),
            'kind' => $m->kind,
            'start' => $o['start']->toDateString(),
            'end' => $o['end']->toDateString(),
            'days_left' => max(0, (int) $today->diffInDays($o['start'], false)),
            'is_today' => $today->between($o['start'], $o['end']),
            'is_visible' => $today->between($o['from'], $o['until']),
            'theme' => $m->theme,
            'primary_color' => $m->primary_color,
            'secondary_color' => $m->secondary_color,
            'emoji' => $m->emoji,
            'animation' => $m->animation,
            'card_image' => $m->cardUrl(),
        ];
    }
}
