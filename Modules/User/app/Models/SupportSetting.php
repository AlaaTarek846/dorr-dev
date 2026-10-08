<?php

namespace Modules\User\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * How support answers on its own (one row): whether automatic replies are on, the acknowledgement sent
 * when a ticket opens, the "we're away" note outside working hours (at most once per `away_every_hours`
 * in a ticket), and whether the AI may answer from the FAQs (at most `ai_max_replies` times a ticket).
 *
 * Texts are per language (`{"ar": "...", "en": "..."}`); an empty one falls back to lang/{locale}/support.php.
 * `hours`: 7 days, Sunday first (Carbon's dayOfWeek), each `{open: bool, from: "09:00", to: "18:00"}` —
 * the same shape as a business's opening hours in DORR Chat.
 */
class SupportSetting extends Model
{
    private const CACHE_KEY = 'support:settings';

    public const KIND_ACK = 'ack';

    public const KIND_AWAY = 'away';

    public const KIND_FAQ = 'faq';

    protected $fillable = [
        'auto_reply_enabled',
        'ack_enabled',
        'ack_message',
        'away_enabled',
        'away_message',
        'hours',
        'timezone',
        'away_every_hours',
        'ai_enabled',
        'ai_max_replies',
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_enabled' => 'boolean',
            'ack_enabled' => 'boolean',
            'ack_message' => 'array',
            'away_enabled' => 'boolean',
            'away_message' => 'array',
            'hours' => 'array',
            'away_every_hours' => 'integer',
            'ai_enabled' => 'boolean',
            'ai_max_replies' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $settings = self::query()->firstOrCreate([], ['hours' => self::defaultHours()]);

            // A row just created only holds what was passed in: read it back for the database defaults
            // (auto replies on, the AI on…) — otherwise every switch would read as "off" until the first save.
            return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
        });
    }

    /**
     * Sunday to Thursday, 09:00–18:00 — until the admin sets the real hours.
     *
     * @return list<array{open: bool, from: string, to: string}>
     */
    public static function defaultHours(): array
    {
        return array_map(fn (int $day) => ['open' => $day <= 4, 'from' => '09:00', 'to' => '18:00'], range(0, 6));
    }

    /**
     * @return list<array{open: bool, from: string, to: string}>
     */
    public static function normalizeHours(?array $hours): array
    {
        $hours = array_values($hours ?? self::defaultHours());

        return array_map(fn (int $i) => [
            'open' => (bool) ($hours[$i]['open'] ?? false),
            'from' => (string) ($hours[$i]['from'] ?? '09:00'),
            'to' => (string) ($hours[$i]['to'] ?? '18:00'),
        ], range(0, 6));
    }

    public function hasHours(): bool
    {
        return collect(self::normalizeHours($this->hours))->contains(fn ($day) => $day['open']);
    }

    /**
     * Support is working at that moment, in its own time zone. No open day at all = never "away".
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        if (! $this->hasHours()) {
            return true;
        }

        $local = $moment->copy()->setTimezone($this->timezone ?: 'UTC');
        $minute = $local->hour * 60 + $local->minute;
        $hours = self::normalizeHours($this->hours);

        // Today's hours, or yesterday's when they run past midnight into today.
        $today = $hours[$local->dayOfWeek];
        $yesterday = $hours[($local->dayOfWeek + 6) % 7];

        if ($today['open']) {
            [$from, $to] = [self::minutes($today['from']), self::minutes($today['to'])];
            if ($from === $to || ($from < $to ? $minute >= $from && $minute < $to : $minute >= $from)) {
                return true;
            }
        }

        if ($yesterday['open']) {
            [$from, $to] = [self::minutes($yesterday['from']), self::minutes($yesterday['to'])];
            if ($from > $to && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text of an automatic reply in the customer's language: the admin's own words when they wrote
     * some, else the default from lang/{locale}/support.php.
     *
     * @param  array<string, scalar>  $variables
     */
    public function textFor(string $kind, string $locale, array $variables = []): string
    {
        $custom = $kind === self::KIND_ACK ? $this->ack_message : $this->away_message;
        $text = trim((string) ($custom[$locale] ?? ''));

        if ($text === '') {
            $text = (string) __('support.auto_'.$kind, [], $locale);
        }

        foreach ($variables as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }

    /**
     * The working hours as people read them: "Sun–Thu 09:00–18:00", in the reader's language.
     */
    public function hoursText(string $locale): string
    {
        $days = (array) __('support.days', [], $locale);
        $parts = [];
        $hours = self::normalizeHours($this->hours);

        // Consecutive days with the same hours read as one range.
        for ($i = 0; $i < 7; $i++) {
            if (! $hours[$i]['open']) {
                continue;
            }

            $j = $i;
            while ($j + 1 < 7 && $hours[$j + 1]['open'] && $hours[$j + 1]['from'] === $hours[$i]['from'] && $hours[$j + 1]['to'] === $hours[$i]['to']) {
                $j++;
            }

            $label = ($days[$i] ?? (string) $i).($j > $i ? '–'.($days[$j] ?? (string) $j) : '');
            $parts[] = $label.' '.$hours[$i]['from'].'–'.$hours[$i]['to'];
            $i = $j;
        }

        return implode($locale === 'ar' ? '، ' : ', ', $parts);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
