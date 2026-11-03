<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A test in an educational group (GR-015). */
class ChatGroupQuiz extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'instructions', 'opens_at', 'closes_at', 'duration_minutes', 'is_published', 'results_visible', 'is_competition', 'reminded_at', 'created_by_participant_id'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'duration_minutes' => 'integer', 'is_published' => 'boolean', 'results_visible' => 'boolean', 'is_competition' => 'boolean', 'reminded_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ChatGroupQuizQuestion::class, 'quiz_id')->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ChatGroupQuizAttempt::class, 'quiz_id');
    }

    public function isOpen(): bool
    {
        return $this->is_published && ($this->opens_at === null || $this->opens_at->isPast()) && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
