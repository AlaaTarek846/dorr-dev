<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The chat limits — one row, edited from the admin dashboard (docs/chat-plan.md §10.0).
 * Read everywhere through current(), which is cached and forgotten on every save.
 */
class ChatSetting extends Model
{
    private const CACHE_KEY = 'chat.settings';

    protected $fillable = [
        'max_group_members',
        'max_file_size_mb',
        'edit_window_minutes',
        'delete_for_everyone_window_minutes',
        'deleted_message_retention_days',
        'max_folders',
        'max_pinned_messages',
        'max_forward_targets',
        'story_duration_hours',
        'story_video_max_seconds',
        'max_call_participants',
        'stories_enabled',
        'calls_enabled',
        'calls_disabled_countries',
        'ai_enabled',
    ];

    protected function casts(): array
    {
        return [
            'max_group_members' => 'integer',
            'max_file_size_mb' => 'integer',
            'edit_window_minutes' => 'integer',
            'delete_for_everyone_window_minutes' => 'integer',
            'deleted_message_retention_days' => 'integer',
            'max_folders' => 'integer',
            'max_pinned_messages' => 'integer',
            'max_forward_targets' => 'integer',
            'story_duration_hours' => 'integer',
            'story_video_max_seconds' => 'integer',
            'max_call_participants' => 'integer',
            'stories_enabled' => 'boolean',
            'calls_enabled' => 'boolean',
            'calls_disabled_countries' => 'array',
            'ai_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Calls are off for everyone, or in this account's country (VoIP needs a licence in some places).
     */
    public function callsAllowedFor(?Model $account): bool
    {
        if (! $this->calls_enabled) {
            return false;
        }

        $country = $account?->getAttribute('country_id');

        return $country === null || ! in_array((int) $country, array_map('intval', $this->calls_disabled_countries ?? []), true);
    }

    /**
     * AI in the chat. On unless the admin switched it off (a copy cached before the column
     * existed has no value — that means on, the column's default).
     */
    public function aiEnabled(): bool
    {
        return $this->ai_enabled ?? true;
    }

    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->firstOrCreate([]));
    }
}
