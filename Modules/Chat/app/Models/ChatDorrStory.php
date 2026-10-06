<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/**
 * One of Dorr's own stories (ads, news), made in the admin — the home page's fixed third circle.
 * Shown between `starts_at` and `ends_at` (either open-ended) while switched on.
 */
class ChatDorrStory extends Model implements HasMedia
{
    use HasMediaTrait;

    public const MEDIA = 'story';

    protected $fillable = ['uuid', 'type', 'body', 'style', 'duration_ms', 'link_url', 'link_label', 'starts_at', 'ends_at', 'status', 'sort_order', 'views_count'];

    protected function casts(): array
    {
        return [
            'style' => 'array',
            'duration_ms' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => 'boolean',
            'sort_order' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeShowing(Builder $query): Builder
    {
        return $query->where('status', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isShowing(): bool
    {
        return $this->status && ($this->starts_at === null || $this->starts_at->isPast()) && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
