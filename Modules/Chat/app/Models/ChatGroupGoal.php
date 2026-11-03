<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A team competition (GR-042): a goal, and points given to members. */
class ChatGroupGoal extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'title', 'description', 'ends_at', 'created_by_participant_id'];

    protected function casts(): array
    {
        return ['ends_at' => 'datetime'];
    }

    public function points(): HasMany
    {
        return $this->hasMany(ChatGroupGoalPoint::class, 'goal_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
