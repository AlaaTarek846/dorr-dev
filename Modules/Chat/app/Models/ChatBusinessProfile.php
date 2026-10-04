<?php

namespace Modules\Chat\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening hours, the welcome message for new customers and the away message when closed
 * (WhatsApp Business). One row per account, created the first time it's saved.
 *
 * `hours`: 7 days, Sunday first (Carbon's dayOfWeek), each `{open: bool, from: "09:00", to: "17:00"}`.
 * A `to` earlier than `from` runs past midnight (a café open 18:00–02:00).
 */
class ChatBusinessProfile extends Model
{
    public const AWAY_ALWAYS = 'always';

    public const AWAY_OUTSIDE_HOURS = 'outside_hours';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'welcome_enabled',
        'welcome_message',
        'away_enabled',
        'away_message',
        'away_mode',
        'hours',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'welcome_enabled' => 'boolean',
            'away_enabled' => 'boolean',
            'hours' => 'array',
        ];
    }

    public function hasHours(): bool
    {
        return collect($this->hours ?? [])->contains(fn ($day) => ! empty($day['open']));
    }

    /**
     * Open at that moment, in the business's own time zone. No hours set = never "closed".
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        if (! $this->hasHours()) {
            return true;
        }

        $local = $moment->copy()->setTimezone($this->timezone ?: 'UTC');
        $minute = $local->hour * 60 + $local->minute;
        $hours = array_values($this->hours ?? []);

        // Today's hours, or yesterday's when they run past midnight into today.
        $today = $hours[$local->dayOfWeek] ?? null;
        $yesterday = $hours[($local->dayOfWeek + 6) % 7] ?? null;

        if (! empty($today['open'])) {
            [$from, $to] = [self::minutes($today['from'] ?? '00:00'), self::minutes($today['to'] ?? '23:59')];
            if ($from === $to || ($from < $to ? $minute >= $from && $minute < $to : $minute >= $from)) {
                return true;
            }
        }

        if (! empty($yesterday['open'])) {
            [$from, $to] = [self::minutes($yesterday['from'] ?? '00:00'), self::minutes($yesterday['to'] ?? '23:59')];
            if ($from > $to && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The away message is due now: it's on, there's something to say, and (unless it's "always")
     * the business is closed.
     */
    public function isAwayAt(CarbonInterface $moment): bool
    {
        if (! $this->away_enabled || trim((string) $this->away_message) === '') {
            return false;
        }

        return $this->away_mode === self::AWAY_ALWAYS || ! $this->isOpenAt($moment);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'welcome_enabled' => (bool) $this->welcome_enabled,
            'welcome_message' => $this->welcome_message,
            'away_enabled' => (bool) $this->away_enabled,
            'away_message' => $this->away_message,
            'away_mode' => $this->away_mode ?: self::AWAY_OUTSIDE_HOURS,
            'hours' => self::normalizeHours($this->hours),
            'timezone' => $this->timezone ?: 'UTC',
        ];
    }

    /**
     * What a customer sees on the chat screen: the week's hours and whether it's open now.
     *
     * @return array<string, mixed>|null
     */
    public function presentPublic(): ?array
    {
        if (! $this->hasHours()) {
            return null;
        }

        return [
            'hours' => self::normalizeHours($this->hours),
            'timezone' => $this->timezone ?: 'UTC',
            'open_now' => $this->isOpenAt(now()),
        ];
    }

    /**
     * Always 7 days, Sunday first, with every field present.
     *
     * @return list<array{open: bool, from: string, to: string}>
     */
    public static function normalizeHours(?array $hours): array
    {
        $hours = array_values($hours ?? []);

        return array_map(fn (int $i) => [
            'open' => (bool) ($hours[$i]['open'] ?? false),
            'from' => (string) ($hours[$i]['from'] ?? '09:00'),
            'to' => (string) ($hours[$i]['to'] ?? '17:00'),
        ], range(0, 6));
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }
}
