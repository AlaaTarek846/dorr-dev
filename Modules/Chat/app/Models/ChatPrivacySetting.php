<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\PrivacyAudience;

class ChatPrivacySetting extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'last_seen',
        'profile_photo',
        'read_receipts',
        'who_can_message',
        'who_can_add_to_groups',
        'who_can_call',
        'who_can_urgent',
        'story_audience',
        'block_screenshots',
        'notification_privacy',
        'status_emoji',
        'status_text',
        'status_until',
        'status_audience',
        'privacy_mode_until',
        'privacy_mode_started_at',
        'privacy_schedule',
        'qr_token',
        'last_seen_at',
    ];

    /**
     * My status ("🏖️ On holiday", until Sunday) while it lasts, or null.
     *
     * @return array{emoji: ?string, text: ?string, until: ?string}|null
     */
    public function activeStatus(): ?array
    {
        if (($this->status_emoji === null && $this->status_text === null) || $this->status_until?->isPast()) {
            return null;
        }

        return ['emoji' => $this->status_emoji, 'text' => $this->status_text, 'until' => $this->status_until?->toIso8601String()];
    }

    /**
     * Privacy mode is on: switched on until a time (spec 111), or inside the daily schedule
     * (spec 112, in the owner's own time zone; a window like 22:00–07:00 runs past midnight).
     */
    public function privacyModeOn(?\Carbon\CarbonInterface $at = null): bool
    {
        $at ??= now();

        if ($this->privacy_mode_until?->gt($at)) {
            return true;
        }

        $schedule = $this->privacy_schedule;
        if (empty($schedule['from']) || empty($schedule['to'])) {
            return false;
        }

        $local = $at->copy()->setTimezone($schedule['timezone'] ?? 'UTC');
        $minutes = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        [$from, $to, $now] = [$minutes($schedule['from']), $minutes($schedule['to']), $local->hour * 60 + $local->minute];
        $days = $schedule['days'] ?? [0, 1, 2, 3, 4, 5, 6];

        if ($from <= $to) {
            return in_array($local->dayOfWeek, $days, true) && $now >= $from && $now < $to;
        }

        // Past midnight: the evening part belongs to today, the morning part to yesterday's window.
        return ($now >= $from && in_array($local->dayOfWeek, $days, true))
            || ($now < $to && in_array(($local->dayOfWeek + 6) % 7, $days, true));
    }

    protected function casts(): array
    {
        return [
            'last_seen' => PrivacyAudience::class,
            'profile_photo' => PrivacyAudience::class,
            'who_can_message' => PrivacyAudience::class,
            'who_can_add_to_groups' => PrivacyAudience::class,
            'who_can_call' => PrivacyAudience::class,
            'who_can_urgent' => PrivacyAudience::class,
            'status_audience' => PrivacyAudience::class,
            'status_until' => 'datetime',
            'privacy_mode_until' => 'datetime',
            'privacy_mode_started_at' => 'datetime',
            'privacy_schedule' => 'array',
            'read_receipts' => 'boolean',
            'block_screenshots' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
