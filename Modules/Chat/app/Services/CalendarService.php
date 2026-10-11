<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Models\ChatCalendarPreference;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatMomentCapsule;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Models\DiscoverInterest;
use Modules\Sports\Models\SportsFollow;
use Modules\Sports\Models\SportsMatch;

/**
 * DORR Calendar & DORR Today (spec 201–207): one calendar of only what I chose to see — my own
 * appointments, occasions (DORR Moments), my own dates, my tasks and my message reminders. Every
 * item is kept as its real instant plus the zone it was set in, and shown in the zone I'm in now
 * (205). Nothing here reads chat messages.
 */
class CalendarService
{
    public const MAX_ITEMS = 5000;

    /** The longest range one request may ask for (a month view with its edges). */
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly MomentService $moments,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    public function enabled(): bool
    {
        return (bool) (ChatSetting::current()->calendar_enabled ?? true);
    }

    public function preferences(Model $me): ChatCalendarPreference
    {
        return ChatCalendarPreference::query()->firstOrNew(['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePreferences(Model $me, array $data): ChatCalendarPreference
    {
        $prefs = $this->preferences($me);
        if (isset($data['sources'])) {
            $prefs->sources = collect(ChatCalendarPreference::SOURCES)->mapWithKeys(fn ($s) => [$s => (bool) ($data['sources'][$s] ?? $prefs->sourceOn($s))])->all();
        }
        foreach (['default_reminders', 'all_day_reminders'] as $key) {
            if (array_key_exists($key, $data)) {
                $prefs->{$key} = $this->cleanReminders($data[$key]);
            }
        }
        if (array_key_exists('today_sections', $data)) {
            $prefs->today_sections = [
                'order' => array_values(array_intersect((array) ($data['today_sections']['order'] ?? []), ChatCalendarPreference::SECTIONS)),
                'hidden' => array_values(array_intersect((array) ($data['today_sections']['hidden'] ?? []), ChatCalendarPreference::SECTIONS)),
            ];
        }
        foreach (['respect_quiet', 'personalised'] as $key) {
            if (array_key_exists($key, $data)) {
                $prefs->{$key} = (bool) $data[$key];
            }
        }
        $prefs->save();

        return $prefs;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPreferences(ChatCalendarPreference $prefs): array
    {
        return [
            'sources' => collect(ChatCalendarPreference::SOURCES)->mapWithKeys(fn ($s) => [$s => $prefs->sourceOn($s)])->all(),
            'default_reminders' => $prefs->defaultReminders(false),
            'all_day_reminders' => $prefs->defaultReminders(true),
            'reminder_choices' => ChatCalendarItem::REMINDER_CHOICES,
            'respect_quiet' => $prefs->respect_quiet ?? true,
            'personalised' => $prefs->personalised ?? true,
            'today_sections' => $prefs->sections(),
        ];
    }

    // ------------------------------------------------------------------ my appointments

    /**
     * A new appointment — by hand, or from a chat (`message_id`). The same title at the same
     * minute (or day) is the same event: it comes back as it is, never twice (AT-CAL-02).
     *
     * @param  array<string, mixed>  $data
     * @return array{item: ChatCalendarItem, duplicate: bool}
     */
    public function create(Model $me, array $data): array
    {
        if (ChatCalendarItem::query()->ownedBy($me)->count() >= self::MAX_ITEMS) {
            throw new ChatException('calendar_limit', 422, ['max' => self::MAX_ITEMS]);
        }

        $message = null;
        if (! empty($data['message_id'])) {
            $message = ChatMessage::query()->where('uuid', $data['message_id'])->with('conversation')->first() ?? throw new ChatException('message_not_found', 404);
            $participant = $this->conversations->participantOf($me, $message->conversation);
            if (! $this->messages->visibleTo($participant, ChatMessage::query()->whereKey($message->id))->exists()) {
                throw new ChatException('message_not_found', 404);
            }
        }

        $fields = $this->fields($data, $me);
        $key = ChatCalendarItem::dedupeKey($fields['title'], $fields['all_day'], $fields['all_day'] ? $fields['date'] : $fields['starts_at']->toIso8601String());
        $existing = ChatCalendarItem::query()->ownedBy($me)->where('dedupe_key', $key)->first();
        if ($existing !== null) {
            return ['item' => $existing, 'duplicate' => true];
        }

        $item = ChatCalendarItem::query()->create($fields + [
            'uuid' => (string) Str::uuid(),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'source' => $message ? 'chat' : 'manual',
            'message_id' => $message?->id,
            'conversation_id' => $message?->conversation_id,
            'dedupe_key' => $key,
            'reminders' => array_key_exists('reminders', $data) ? $this->cleanReminders($data['reminders']) : $this->preferences($me)->defaultReminders($fields['all_day']),
        ]);

        return ['item' => $item, 'duplicate' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $me, ChatCalendarItem $item, array $data): ChatCalendarItem
    {
        $this->assertMine($me, $item);
        $merged = array_merge([
            'title' => $item->title, 'note' => $item->note, 'location' => $item->location, 'all_day' => $item->all_day,
            'starts_at' => $item->starts_at?->toIso8601String(), 'ends_at' => $item->ends_at?->toIso8601String(),
            'date' => $item->date?->toDateString(), 'timezone' => $item->timezone, 'color' => $item->color,
        ], $data);
        $fields = $this->fields($merged, $me);
        $key = ChatCalendarItem::dedupeKey($fields['title'], $fields['all_day'], $fields['all_day'] ? $fields['date'] : $fields['starts_at']->toIso8601String());
        if (ChatCalendarItem::query()->ownedBy($me)->where('dedupe_key', $key)->whereKeyNot($item->id)->exists()) {
            throw new ChatException('calendar_duplicate', 422);
        }

        $moved = ! $item->starts_at?->equalTo($fields['starts_at']) || ($item->date?->toDateString() !== $fields['date']);
        $item->update($fields + [
            'dedupe_key' => $key,
            'reminders' => array_key_exists('reminders', $data) ? $this->cleanReminders($data['reminders']) : $item->reminders,
            // A new time is reminded again.
            'reminded' => $moved || array_key_exists('reminders', $data) ? null : $item->reminded,
        ]);

        return $item->refresh();
    }

    public function assertMine(Model $me, ChatCalendarItem $item): void
    {
        if (! $item->isOwnedBy($me)) {
            throw new ChatException('calendar_not_found', 404);
        }
    }

    /**
     * Validated fields → stored ones: the instant in UTC, the zone it was set in, the day for
     * all-day ones.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, Model $me): array
    {
        $zone = $this->zone($data['timezone'] ?? ($me->timezone ?? null));
        $allDay = (bool) ($data['all_day'] ?? false);
        if ($allDay) {
            $date = CarbonImmutable::parse((string) ($data['date'] ?? $data['starts_at'] ?? ''), $zone)->toDateString();
            $start = CarbonImmutable::parse($date, $zone)->startOfDay()->utc();
            $end = null;
        } else {
            if (empty($data['starts_at'])) {
                throw new ChatException('calendar_time_required', 422);
            }
            $start = CarbonImmutable::parse((string) $data['starts_at'], $zone)->utc();
            $end = ! empty($data['ends_at']) ? CarbonImmutable::parse((string) $data['ends_at'], $zone)->utc() : null;
            if ($end !== null && $end->lessThanOrEqualTo($start)) {
                $end = null;
            }
            $date = null;
        }

        return [
            'title' => Str::limit(trim((string) $data['title']), 160, ''),
            'note' => ($n = trim((string) ($data['note'] ?? ''))) !== '' ? Str::limit($n, 1000, '') : null,
            'location' => ($l = trim((string) ($data['location'] ?? ''))) !== '' ? Str::limit($l, 200, '') : null,
            'all_day' => $allDay,
            'starts_at' => $start,
            'ends_at' => $end,
            'date' => $date,
            'timezone' => $zone,
            'color' => $data['color'] ?? null,
        ];
    }

    /**
     * @return list<int>
     */
    private function cleanReminders(mixed $value): array
    {
        return collect((array) $value)->map(fn ($m) => (int) $m)->filter(fn ($m) => in_array($m, ChatCalendarItem::REMINDER_CHOICES, true))->unique()->sort()->take(5)->values()->all();
    }

    private function zone(?string $zone): string
    {
        return $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : (string) config('app.timezone', 'UTC');
    }

    // ------------------------------------------------------------------ the calendar

    /**
     * Everything in [from, to] (days in `$zone`), from the sources I keep on — or only `$types`.
     *
     * @param  list<string>|null  $types
     * @return array{enabled: bool, items: list<array<string, mixed>>}
     */
    public function feed(Model $me, string $from, string $to, string $zone, ?array $types = null): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'items' => []];
        }
        $zone = $this->zone($zone);
        $start = CarbonImmutable::parse($from, $zone)->startOfDay();
        $end = CarbonImmutable::parse($to, $zone)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw new ChatException('calendar_range', 422, ['max' => self::MAX_RANGE_DAYS]);
        }

        return ['enabled' => true, 'items' => $this->collect($me, $start, $end->endOfDay(), $zone, $types)->values()->all()];
    }

    /**
     * @param  list<string>|null  $types
     * @return Collection<int, array<string, mixed>>
     */
    private function collect(Model $me, CarbonImmutable $from, CarbonImmutable $to, string $zone, ?array $types): Collection
    {
        $prefs = $this->preferences($me);
        $want = fn (string $source) => $prefs->sourceOn($source) && ($types === null || in_array($source, $types, true));
        $items = collect();

        if ($want('events')) {
            $items = $items->concat($this->events($me, $from, $to, $zone));
        }
        if ($want('tasks')) {
            $items = $items->concat(ChatTask::query()->ownedBy($me)->whereBetween('due_at', [$from->utc(), $to->utc()])->with(['message', 'conversation'])->get()
                ->map(fn (ChatTask $t) => $this->row('task', $t->uuid, $t->text, $t->due_at, null, false, null, $zone, [
                    'done' => $t->done_at !== null, 'message_id' => $t->message?->uuid, 'conversation_id' => $t->conversation?->uuid,
                ])));
        }
        if ($want('reminders')) {
            $items = $items->concat($this->reminders($me, $from, $to, $zone));
        }
        if ($want('moments') || $want('personal')) {
            $items = $items->concat($this->occasions($me, $from, $to, $want('moments'), $want('personal')));
        }
        if ($want('discover')) {
            $items = $items->concat($this->discover($me, $from, $to, $zone));
        }
        if ($want('sports')) {
            $items = $items->concat($this->sports($me, $from, $to, $zone));
        }

        return $this->dedupe($items)->sortBy(fn ($i) => [$i['date'], $i['all_day'] ? 0 : 1, $i['starts_at'] ?? ''])->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function events(Model $me, CarbonImmutable $from, CarbonImmutable $to, string $zone): Collection
    {
        return ChatCalendarItem::query()->ownedBy($me)->with(['message', 'conversation'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('all_day', true)->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
                ->orWhere(fn ($q) => $q->where('all_day', false)->where('starts_at', '<=', $to->utc())->where(fn ($q) => $q->where('starts_at', '>=', $from->utc())->orWhere('ends_at', '>=', $from->utc()))))
            ->get()->map(fn (ChatCalendarItem $i) => $this->presentItem($i, $zone));
    }

    /**
     * One appointment, as the calendar shows it.
     *
     * @return array<string, mixed>
     */
    public function presentItem(ChatCalendarItem $i, ?string $zone = null): array
    {
        $zone = $this->zone($zone ?? $i->timezone);

        return $this->row('event', $i->uuid, $i->title, $i->all_day ? null : $i->starts_at, $i->all_day ? null : $i->ends_at, $i->all_day, $i->date?->toDateString(), $zone, [
            'timezone' => $i->timezone,
            // "15:00 in Riyadh" when I'm somewhere else now (205).
            'origin_time' => ! $i->all_day && $i->timezone !== $zone ? $i->starts_at->setTimezone($i->timezone)->format('H:i') : null,
            'location' => $i->location,
            'note' => $i->note,
            'color' => $i->color,
            'source' => $i->source,
            'reminders' => $i->reminders ?? [],
            'message_id' => $i->message?->uuid,
            'conversation_id' => $i->conversation?->uuid,
        ]);
    }

    /**
     * DORR Discover events I said "interested" to (spec 177) — at their real time, with their own
     * local time when I'm elsewhere (205).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function discover(Model $me, CarbonImmutable $from, CarbonImmutable $to, string $zone): Collection
    {
        if (! class_exists(DiscoverInterest::class)) {
            return collect();
        }

        return DiscoverInterest::query()->ownedBy($me)
            ->whereHas('event', fn ($q) => $q->where('review_status', 'approved')->where('starts_at', '<=', $to->utc())
                ->where(fn ($q) => $q->where('starts_at', '>=', $from->utc())->orWhere('ends_at', '>=', $from->utc())))
            ->with('event.city.translations')->get()
            ->map(fn ($i) => $this->row('discover', $i->event->uuid, $i->event->title, $i->event->starts_at, $i->event->ends_at, false, null, $zone, [
                'timezone' => $i->event->timezone,
                'origin_time' => $i->event->timezone !== $zone ? $i->event->starts_at->setTimezone($i->event->timezone)->format('H:i') : null,
                'location' => trim(implode(' · ', array_filter([$i->event->venue, $i->event->city?->translatedName()]))) ?: null,
                'status' => $i->event->status,
            ]));
    }

    /**
     * DORR Sports: matches of the teams I follow (spec 201–203).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sports(Model $me, CarbonImmutable $from, CarbonImmutable $to, string $zone): Collection
    {
        if (! class_exists(SportsFollow::class)) {
            return collect();
        }
        $follows = SportsFollow::query()->ownedBy($me)->get(['kind', 'target_id']);
        $teams = $follows->where('kind', 'team')->pluck('target_id')->all();
        // A race has no teams: following the championship brings its races.
        $races = $follows->where('kind', 'competition')->pluck('target_id')->all();
        if ($teams === [] && $races === []) {
            return collect();
        }

        return SportsMatch::query()
            ->where(fn ($q) => $q->whereIn('home_team_id', $teams ?: [0])->orWhereIn('away_team_id', $teams ?: [0])
                ->orWhere(fn ($q) => $q->whereNull('home_team_id')->whereIn('competition_id', $races ?: [0])))
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->whereHas('competition', fn ($q) => $q->where('tier', '!=', 'off'))
            ->with(['home', 'away', 'competition', 'sport'])->limit(200)->get()
            ->map(fn ($m) => $this->row('sports', $m->uuid, $m->home_team_id === null ? (string) ($m->round ?? $m->competition?->name) : trim(($m->home?->name ?? '?').' – '.($m->away?->name ?? '?')), $m->starts_at, null, false, null, $zone, [
                'location' => $m->competition?->name,
                'status' => $m->status,
                'emoji' => $m->sport ? $m->sport->emoji() : '🏆',
                'home_score' => $m->home_score,
                'away_score' => $m->away_score,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function reminders(Model $me, CarbonImmutable $from, CarbonImmutable $to, string $zone): Collection
    {
        $mine = ChatParticipant::query()->where('participant_type', ParticipantType::aliasFor($me))->where('participant_id', $me->getKey())->pluck('id');

        return DB::table('chat_message_reminders as r')
            ->join('chat_messages as m', 'm.id', '=', 'r.message_id')
            ->join('chat_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('r.participant_id', $mine)->whereBetween('r.remind_at', [$from->utc(), $to->utc()])
            ->whereNull('m.deleted_for_everyone_at')
            ->get(['r.id', 'r.remind_at', 'r.note', 'r.sent_at', 'm.uuid as message_uuid', 'c.uuid as conversation_uuid'])
            ->map(fn ($r) => $this->row('reminder', (string) $r->id, $r->note ?: __('chat.calendar.reminder_title'), CarbonImmutable::parse($r->remind_at, 'UTC'), null, false, null, $zone, [
                'done' => $r->sent_at !== null, 'message_id' => $r->message_uuid, 'conversation_id' => $r->conversation_uuid,
            ]));
    }

    /**
     * Occasions I see (DORR Moments) and my own dates, as all-day items.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function occasions(Model $me, CarbonImmutable $from, CarbonImmutable $to, bool $moments, bool $personal): Collection
    {
        $prefs = $this->moments->preferences($me);
        if (! ((ChatSetting::current()->moments_enabled ?? true) && $prefs->enabled)) {
            return collect();
        }
        $years = range($from->year, $to->year);
        $first = $from->toDateString();
        $last = $to->toDateString();
        $out = collect();

        if ($moments) {
            $country = $this->moments->countryFor($me, $prefs);
            foreach ($this->moments->mine($me, $prefs, $country) as $m) {
                foreach ([...$years, $from->year - 1] as $year) {
                    foreach ($this->moments->datesIn($m, $country, $year) as $day) {
                        $endDay = $day->addDays(max(1, $m->duration_days) - 1);
                        if ($endDay->toDateString() < $first || $day->toDateString() > $last) {
                            continue;
                        }
                        $out->push($this->row('moment', (string) $m->id, (string) $m->translatedName(), null, null, true, $day->toDateString(), null, [
                            'end_date' => $endDay->toDateString(), 'emoji' => $m->emoji, 'color' => $m->primary_color, 'secondary_color' => $m->secondary_color, 'kind' => $m->kind,
                        ]));
                    }
                }
            }
        }

        if ($personal) {
            foreach (ChatPersonalMoment::query()->ownedBy($me)->get() as $p) {
                foreach ($years as $year) {
                    $day = checkdate($p->month, $p->day, $year) ? CarbonImmutable::create($year, $p->month, $p->day) : CarbonImmutable::create($year, $p->month, 28);
                    if ($day->toDateString() < $first || $day->toDateString() > $last) {
                        continue;
                    }
                    $look = MomentService::PERSONAL_LOOKS[$p->kind] ?? MomentService::PERSONAL_LOOKS['other'];
                    $out->push($this->row('personal', $p->uuid, $p->title, null, null, true, $day->toDateString(), null, [
                        'emoji' => $look['emoji'], 'color' => $look['primary_color'], 'kind' => $p->kind, 'turns' => $p->year ? $year - $p->year : null,
                    ]));
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $type, string $ref, string $title, ?CarbonInterface $start, ?CarbonInterface $end, bool $allDay, ?string $date, ?string $zone, array $extra = []): array
    {
        return [
            'id' => $type.':'.$ref,
            'type' => $type,
            'ref' => $ref,
            'title' => $title,
            'starts_at' => $start?->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'all_day' => $allDay,
            // The day it falls on where I am now (all-day ones keep their own date).
            'date' => $date ?? $start?->setTimezone($zone ?? 'UTC')->toDateString(),
        ] + $extra;
    }

    /**
     * The same event from two sources shows once (AT-CAL-02): an appointment beats a reminder on
     * the same message at about the same time, and anything beats an identical all-day twin.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function dedupe(Collection $items): Collection
    {
        $events = $items->where('type', 'event');
        $items = $items->reject(fn ($i) => $i['type'] === 'reminder' && $events->contains(fn ($e) => $e['message_id'] !== null && $e['message_id'] === ($i['message_id'] ?? null)
            && $e['starts_at'] !== null && abs(CarbonImmutable::parse($e['starts_at'])->diffInMinutes(CarbonImmutable::parse($i['starts_at']), true)) <= 10));

        $rank = ['event' => 0, 'discover' => 1, 'sports' => 2, 'personal' => 3, 'moment' => 4, 'task' => 5, 'reminder' => 6];

        return $items->sortBy(fn ($i) => $rank[$i['type']] ?? 9)
            ->unique(fn ($i) => $i['all_day'] ? mb_strtolower(trim($i['title'])).'|'.$i['date'] : $i['id'])
            ->values();
    }

    // ------------------------------------------------------------------ DORR Today (202, 207)

    /**
     * Today where I am, in my order: what's next, appointments, tasks (and overdue ones),
     * reminders, occasions — and "around my interests" (what's coming, what I might miss).
     *
     * @return array<string, mixed>
     */
    public function today(Model $me, string $zone): array
    {
        $prefs = $this->preferences($me);
        $zone = $this->zone($zone);
        $now = CarbonImmutable::now($zone);
        $base = ['enabled' => $this->enabled(), 'date' => $now->toDateString(), 'timezone' => $zone, 'sections' => $prefs->sections()];
        if (! $base['enabled']) {
            return $base + ['next' => null, 'events' => [], 'tasks' => [], 'reminders' => [], 'moments' => [], 'around' => null];
        }

        $day = $this->collect($me, $now->startOfDay(), $now->endOfDay(), $zone, null);
        $overdue = $prefs->sourceOn('tasks')
            ? ChatTask::query()->ownedBy($me)->whereNull('done_at')->where('due_at', '<', $now->startOfDay()->utc())->orderBy('due_at')->limit(20)->with(['message', 'conversation'])->get()
                ->map(fn (ChatTask $t) => $this->row('task', $t->uuid, $t->text, $t->due_at, null, false, null, $zone, ['done' => false, 'overdue' => true, 'message_id' => $t->message?->uuid, 'conversation_id' => $t->conversation?->uuid]))
            : collect();

        $timed = $day->filter(fn ($i) => ! $i['all_day'] && ! ($i['done'] ?? false) && $i['type'] !== 'moment');
        $next = $timed->first(fn ($i) => CarbonImmutable::parse($i['ends_at'] ?? $i['starts_at'])->gte($now->utc()));

        return $base + [
            'next' => $next,
            'events' => $day->whereIn('type', ['event', 'discover', 'sports'])->values()->all(),
            'tasks' => $overdue->concat($day->where('type', 'task'))->values()->all(),
            'reminders' => $day->where('type', 'reminder')->values()->all(),
            'moments' => $day->whereIn('type', ['moment', 'personal'])->values()->all(),
            'around' => ($prefs->personalised ?? true) ? $this->around($me, $now, $zone, $overdue->count()) : null,
        ];
    }

    /**
     * "Around my interests" (207): what's coming this week and what I might miss, from what I
     * chose — with what it's based on, so I can change it.
     *
     * @return array<string, mixed>
     */
    private function around(Model $me, CarbonImmutable $now, string $zone, int $overdue): array
    {
        $week = $this->collect($me, $now->addDay()->startOfDay(), $now->addDays(7)->endOfDay(), $zone, null);
        $miss = collect();
        if ($overdue > 0) {
            $miss->push(['kind' => 'overdue_tasks', 'count' => $overdue]);
        }
        // My own dates tied to someone, coming soon: a card to send.
        foreach ($week->where('type', 'personal') as $p) {
            $miss->push(['kind' => 'personal_soon', 'title' => $p['title'], 'date' => $p['date'], 'emoji' => $p['emoji'] ?? null]);
        }
        // An early appointment tomorrow.
        $early = $week->first(fn ($i) => $i['type'] === 'event' && ! $i['all_day'] && $i['date'] === $now->addDay()->toDateString() && CarbonImmutable::parse($i['starts_at'])->setTimezone($zone)->hour < 9);
        if ($early !== null) {
            $miss->push(['kind' => 'early_tomorrow', 'title' => $early['title'], 'starts_at' => $early['starts_at']]);
        }

        $momentPrefs = $this->moments->preferences($me);
        $country = $this->moments->countryFor($me, $momentPrefs);

        return [
            'week_count' => $week->count(),
            'coming' => $week->take(6)->values()->all(),
            'might_miss' => $miss->take(6)->values()->all(),
            // What it's based on — editable (spec 30.14).
            'based_on' => [
                'country' => $country?->translatedName(),
                'picked_occasions' => count($momentPrefs->picked ?? []),
                'sources' => collect(ChatCalendarPreference::SOURCES)->filter(fn ($s) => $this->preferences($me)->sourceOn($s))->values()->all(),
            ],
        ];
    }

    // ------------------------------------------------------------------ search (206)

    /**
     * One search over my calendar, tasks, own dates, occasions and capsules — never over messages
     * (nothing of a chat is read).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function search(Model $me, string $query, string $zone): array
    {
        $q = trim($query);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $zone = $this->zone($zone);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $out = collect();

        ChatCalendarItem::query()->ownedBy($me)->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('note', 'like', $like)->orWhere('location', 'like', $like))
            ->with(['message', 'conversation'])->orderByDesc('starts_at')->limit(20)->get()
            ->each(fn ($i) => $out->push($this->presentItem($i, $zone)));
        ChatTask::query()->ownedBy($me)->where('text', 'like', $like)->with(['message', 'conversation'])->latest('id')->limit(20)->get()
            ->each(fn ($t) => $out->push($this->row('task', $t->uuid, $t->text, $t->due_at, null, false, $t->due_at ? null : $today->toDateString(), $zone, [
                'done' => $t->done_at !== null, 'message_id' => $t->message?->uuid, 'conversation_id' => $t->conversation?->uuid,
            ])));
        ChatPersonalMoment::query()->ownedBy($me)->where('title', 'like', $like)->limit(20)->get()
            ->each(function ($p) use ($out, $today) {
                $next = $this->moments->nextYearly($p->month, $p->day, $today);
                $out->push($this->row('personal', $p->uuid, $p->title, null, null, true, $next->toDateString(), null, ['emoji' => (MomentService::PERSONAL_LOOKS[$p->kind] ?? MomentService::PERSONAL_LOOKS['other'])['emoji'], 'kind' => $p->kind]));
            });
        $prefs = $this->moments->preferences($me);
        $country = $this->moments->countryFor($me, $prefs);
        ChatMoment::query()->active()->whereHas('translations', fn ($t) => $t->where('name', 'like', $like))->with('translations')->limit(15)->get()
            ->each(function (ChatMoment $m) use ($out, $country, $today) {
                $o = $this->moments->occurrence($m, $country, $today);
                $out->push($this->row('moment', (string) $m->id, (string) $m->translatedName(), null, null, true, $o ? $o['start']->toDateString() : null, null, ['emoji' => $m->emoji, 'color' => $m->primary_color]));
            });
        ChatMomentCapsule::query()->ownedBy($me)->where('title', 'like', $like)->withCount('items')->limit(10)->get()
            ->each(fn ($c) => $out->push(['id' => 'capsule:'.$c->uuid, 'type' => 'capsule', 'ref' => $c->uuid, 'title' => $c->title, 'emoji' => $c->emoji, 'count' => $c->items_count, 'all_day' => true, 'date' => null, 'starts_at' => null]));

        return ['items' => $out->values()->all()];
    }
}
