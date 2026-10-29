<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A book or text on a class's reading list (GR-026). */
class ChatGroupReadingItem extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'author', 'link', 'pages', 'due_at', 'created_by_participant_id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'pages' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function progress(): HasMany
    {
        return $this->hasMany(ChatGroupReadingProgress::class, 'item_id');
    }
}
