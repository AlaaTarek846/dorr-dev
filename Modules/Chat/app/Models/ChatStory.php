<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

class ChatStory extends Model implements HasMedia
{
    use HasMediaTrait;

    public const MEDIA = 'story';

    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'type', 'body', 'style', 'duration_ms', 'allow_replies', 'is_public', 'expires_at'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'style' => 'array',
            'duration_ms' => 'integer',
            'allow_replies' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $story) {
            $story->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function views(): HasMany
    {
        return $this->hasMany(ChatStoryView::class, 'story_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function ownerKey(): string
    {
        return $this->owner_type.':'.$this->owner_id;
    }

    public function isOwnedBy(string $type, int $id): bool
    {
        return $this->owner_type === $type && (int) $this->owner_id === $id;
    }
}
