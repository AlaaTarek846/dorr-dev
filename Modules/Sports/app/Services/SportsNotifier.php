<?php

namespace Modules\Sports\Services;

use App\Support\LocaleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Sports\Models\SportsFollow;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsPreference;
use Throwable;

/**
 * Alerts for the people who follow a match's teams or competition (193), each by their own
 * choices: in the app at once on their private Pusher channel (the goal moment, 197–198), and as a
 * OneSignal push. One of a kind per person per match. "No spoilers" (194) sends no score and no
 * celebration; quiet hours hold the pushes (goals too, unless asked for).
 */
class SportsNotifier
{
    /** change type → the alert that covers it */
    private const ALERT_OF = [
        'kickoff' => 'kickoff', 'goal' => 'goal', 'goal_detail' => 'goal', 'red_card' => 'red_card', 'half_time' => 'half_time',
        'finished' => 'finished', 'postponed' => 'schedule', 'cancelled' => 'schedule', 'suspended' => 'schedule', 'time_changed' => 'schedule',
        'lineups' => 'lineups',
    ];

    public function __construct(
        private readonly ChatPushNotifier $push,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function changed(SportsMatch $match, array $changes): void
    {
        try {
            $followers = $this->followers($match);
            if ($followers->isEmpty()) {
                return;
            }
            $match->loadMissing(['home.translations', 'away.translations', 'competition.translations']);
            foreach ($changes as $change) {
                $alert = self::ALERT_OF[$change['type']] ?? null;
                if ($alert !== null) {
                    $this->send($match, $change, $alert, $followers);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[sports] alerts: '.$e->getMessage());
        }
    }

    /** A reminder before kick-off (the `sports:reminders` command). */
    public function reminder(SportsMatch $match, int $minutesLeft): int
    {
        // Each person at their own time before (the log keeps it to one).
        $followers = $this->followers($match)->filter(fn ($f) => $f['prefs']->reminder_minutes > 0 && $minutesLeft <= $f['prefs']->reminder_minutes);

        return $this->send($match, ['type' => 'reminder', 'minutes' => $minutesLeft], 'reminder', $followers);
    }

    /**
     * After quiet hours: one push per person with every match that changed meanwhile, at its
     * score now (no score when spoilers are hidden). Returns how many people were told.
     */
    public function flushHeld(): int
    {
        $rows = DB::table('sports_held_alerts')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $sent = 0;
        foreach ($rows->groupBy(fn ($r) => $r->owner_type.':'.$r->owner_id) as $key => $held) {
            [$type, $id] = explode(':', $key);
            $quiet = ChatPrivacySetting::query()->where('owner_type', $type)->where('owner_id', $id)->first();
            if ($quiet?->quietOn()) {
                continue;
            }
            $matches = SportsMatch::query()->with(['home.translations', 'away.translations', 'competition'])->whereIn('id', $held->pluck('match_id'))->get()->keyBy('id');
            $contents = [];
            $headings = [];
            foreach (LocaleResolver::supported() as $locale) {
                $headings[$locale] = '🌙 '.__('sports.push.digest_title', [], $locale);
                $lines = $held->map(function ($h) use ($matches, $locale) {
                    $m = $matches->get($h->match_id);
                    if ($m === null) {
                        return null;
                    }
                    $name = fn ($t) => $t?->translations?->firstWhere('locale', $locale)?->name ?? $t?->name ?? '?';
                    if ($m->home_team_id === null) {
                        return (string) ($m->round ?? $m->competition?->name);
                    }

                    return $h->hidden || $m->home_score === null
                        ? $name($m->home).' – '.$name($m->away)
                        : $name($m->home).' '.$m->home_score.' – '.$m->away_score.' '.$name($m->away).($m->status === 'finished' ? ' ('.__('sports.push.ft_short', [], $locale).')' : '');
                })->filter()->values()->all();
                $contents[$locale] = implode(' · ', $lines);
            }
            $this->push->toAccounts([[$type, (int) $id]], $headings, $contents, ['type' => 'sports', 'event' => 'sports.digest']);
            DB::table('sports_held_alerts')->whereIn('id', $held->pluck('id'))->delete();
            $sent++;
        }

        return $sent;
    }

    /**
     * Everyone following either team or the competition, with their merged choices.
     *
     * @return Collection<string, array{owner: array{0: string, 1: int}, follow: SportsFollow, prefs: SportsPreference, sides: list<string>}>
     */
    public function followers(SportsMatch $match): Collection
    {
        $rows = SportsFollow::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', 'team')->whereIn('target_id', array_filter([$match->home_team_id, $match->away_team_id])))
                ->orWhere(fn ($q) => $q->where('kind', 'competition')->where('target_id', $match->competition_id)))
            ->get();
        if ($rows->isEmpty()) {
            return collect();
        }
        $prefs = SportsPreference::query()->where(function ($q) use ($rows) {
            foreach ($rows->groupBy('owner_type') as $type => $group) {
                $q->orWhere(fn ($q) => $q->where('owner_type', $type)->whereIn('owner_id', $group->pluck('owner_id')));
            }
        })->get()->keyBy(fn ($p) => $p->owner_type.':'.$p->owner_id);

        return $rows->groupBy(fn ($f) => $f->owner_type.':'.$f->owner_id)->map(function (Collection $own, string $key) use ($prefs, $match) {
            // A team follow beats a competition follow for the choices.
            $follow = $own->firstWhere('kind', 'team') ?? $own->first();
            $sides = $own->where('kind', 'team')->map(fn ($f) => (int) $f->target_id === (int) $match->home_team_id ? 'home' : 'away')->values()->all();

            return [
                'owner' => [$follow->owner_type, (int) $follow->owner_id],
                'follow' => $follow,
                'prefs' => $prefs->get($key) ?? new SportsPreference,
                'sides' => $sides,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<string, array<string, mixed>>  $followers
     */
    private function send(SportsMatch $match, array $change, string $alert, Collection $followers): int
    {
        $key = $this->key($match, $change);
        $targets = $followers->filter(fn ($f) => $f['follow']->wants($alert));
        if ($targets->isEmpty()) {
            return 0;
        }

        // Never twice: claim the (owner, match, key) rows first.
        $fresh = $targets->filter(function ($f) use ($match, $key) {
            return DB::table('sports_notification_log')->insertOrIgnore([
                'owner_type' => $f['owner'][0], 'owner_id' => $f['owner'][1], 'match_id' => $match->id, 'key' => $key, 'sent_at' => now(),
            ]) > 0;
        });
        if ($fresh->isEmpty()) {
            return 0;
        }

        $quiet = ChatPrivacySetting::query()->where(function ($q) use ($fresh) {
            foreach ($fresh as $f) {
                $q->orWhere(fn ($q) => $q->where('owner_type', $f['owner'][0])->where('owner_id', $f['owner'][1]));
            }
        })->get()->filter(fn ($s) => $s->quietOn())->keyBy(fn ($s) => $s->owner_type.':'.$s->owner_id);

        $groups = ['plain' => [], 'spoiler_free' => []];
        foreach ($fresh as $ownerKey => $f) {
            $hide = $f['prefs']->no_spoilers || $f['follow']->no_spoilers;
            $mine = $change['side'] ?? null;
            // In the app, right now: the goal moment / win celebration if it's my team (197, 198).
            $this->broadcaster->toAccounts([$f['owner']], 'sports.alert', [
                'match_id' => $match->uuid,
                'type' => $change['type'],
                'alert' => $alert,
                'hidden' => $hide,
                'home_score' => $hide ? null : $match->home_score,
                'away_score' => $hide ? null : $match->away_score,
                'side' => $hide ? null : $mine,
                'scorer' => $hide ? null : ($change['scorer'] ?? $change['player'] ?? null),
                'minute' => $hide ? null : ($change['minute'] ?? null),
                'celebrate' => ! $hide && $f['prefs']->celebration !== 'off' && (
                    ($alert === 'goal' && $mine !== null && in_array($mine, $f['sides'], true))
                    || ($change['type'] === 'finished' && $match->winner !== null && in_array($match->winner, $f['sides'], true))
                ) ? $f['prefs']->celebration : null,
            ]);

            $held = isset($quiet[$ownerKey]) && ! ($alert === 'goal' && $f['prefs']->goals_in_quiet);
            if (! $held) {
                $groups[$hide ? 'spoiler_free' : 'plain'][] = $f['owner'];
            } elseif ($alert !== 'reminder') {
                // Summed up when the quiet hours end.
                DB::table('sports_held_alerts')->insertOrIgnore([
                    'owner_type' => $f['owner'][0], 'owner_id' => $f['owner'][1], 'match_id' => $match->id, 'hidden' => $hide, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach ($groups as $variant => $owners) {
            if ($owners === []) {
                continue;
            }
            $headings = [];
            $contents = [];
            foreach (LocaleResolver::supported() as $locale) {
                [$headings[$locale], $contents[$locale]] = $this->text($match, $change, $variant === 'spoiler_free', $locale);
            }
            $this->push->toAccounts($owners, $headings, $contents, ['type' => 'sports', 'event' => 'sports.'.$change['type'], 'match_id' => $match->uuid]);
        }

        return $fresh->count();
    }

    /** @param  array<string, mixed>  $change */
    private function key(SportsMatch $match, array $change): string
    {
        return match ($change['type']) {
            'goal', 'goal_detail' => 'goal:'.($change['home'] ?? $match->home_score).'-'.($change['away'] ?? $match->away_score),
            'red_card' => 'red:'.substr(md5(($change['player'] ?? '').($change['minute'] ?? '')), 0, 10),
            'time_changed' => 'time:'.$match->starts_at?->toIso8601String(),
            'half_time', 'resumed' => $change['type'].':'.($change['code'] ?? ''),
            default => $change['type'],
        };
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string}
     */
    private function text(SportsMatch $match, array $change, bool $hide, string $locale): array
    {
        $home = $match->home?->translations?->firstWhere('locale', $locale)?->name ?? $match->home?->name ?? '?';
        $away = $match->away?->translations?->firstWhere('locale', $locale)?->name ?? $match->away?->name ?? '?';
        $title = $match->home_team_id === null ? (string) ($match->round ?? $match->competition?->name ?? '') : "{$home} – {$away}";
        $score = "{$home} {$match->home_score} – {$match->away_score} {$away}";
        $zone = (string) config('app.timezone', 'UTC');
        $vars = [
            'score' => $score,
            'scorer' => $change['scorer'] ?? $change['player'] ?? '',
            'minute' => $change['minute'] ?? '',
            'team' => ($change['side'] ?? 'home') === 'home' ? $home : $away,
            'minutes' => $change['minutes'] ?? '',
            'time' => $match->starts_at?->setTimezone($zone)->format('H:i') ?? '',
        ];
        if ($hide && in_array($change['type'], ['goal', 'goal_detail', 'red_card', 'finished', 'half_time'], true)) {
            return ['⚽ '.$title, (string) __('sports.push.hidden', [], $locale)];
        }
        $key = match (true) {
            // A race: lights out, and who won.
            $match->home_team_id === null && $change['type'] === 'kickoff' => 'race_start',
            $match->home_team_id === null && $change['type'] === 'finished' => 'race_finished',
            in_array($change['type'], ['goal', 'goal_detail'], true) => ! empty($vars['scorer']) ? 'goal_by' : 'goal',
            default => $change['type'],
        };
        $vars['winner'] = (string) (data_get($match->scores, 'results.0.driver') ?? '');

        return [$this->emoji($change['type']).' '.$title, (string) __('sports.push.'.$key, $vars, $locale)];
    }

    private function emoji(string $type): string
    {
        return match ($type) {
            'goal', 'goal_detail' => '⚽',
            'red_card' => '🟥',
            'finished' => '🏁',
            'kickoff' => '▶️',
            'reminder' => '⏰',
            'lineups' => '📋',
            'postponed', 'cancelled', 'suspended', 'time_changed' => '📅',
            default => '🏟️',
        };
    }
}
