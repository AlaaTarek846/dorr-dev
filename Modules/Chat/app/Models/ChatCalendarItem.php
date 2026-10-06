<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Support\ParticipantType;

/** One of my appointments in DORR Calendar (spec 201, 205). */
class ChatCalendarItem extends Model
{
    public const REMINDER_CHOICES = [0, 5, 10, 15, 30, 60, 120, 180, 1440, 2880, 10080];

    protected $fillable = [
        'uuid', 'owner_type', 'owner_id', 'title', 'note', 'location', 'starts_at', 'ends_at', 'all_day', 'date', 'timezone', 'color',
        'source', 'message_id', 'conversation_id', 'dedupe_key', 'reminders', 'reminded',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean', 'date' => 'date',
            'reminders' => 'array', 'reminded' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }

    /** Same title at the same minute (or the same day for all-day ones) = the same event. */
    public static function dedupeKey(string $title, bool $allDay, string $startUtc): string
    {
        $norm = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));

        return md5($norm.'|'.($allDay ? substr($startUtc, 0, 10) : substr($startUtc, 0, 16)));
    }
}
