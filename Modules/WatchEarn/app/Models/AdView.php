<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/**
 * One viewer and one ad (WE-031): started → completed (charged once, the server checks the time) → maybe interacted
 * within the window (the interaction share, held at completion, becomes their reward).
 */
class AdView extends Model
{
    protected $table = 'we_views';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'interaction_deadline_at' => 'datetime',
            'interacted_at' => 'datetime', 'interaction_settled_at' => 'datetime', 'interaction_hold' => 'array',
            'charged_minor' => 'integer', 'media_version' => 'integer', 'city_id' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function canInteract(): bool
    {
        return $this->completed_at !== null && $this->interacted_at === null && $this->interaction_settled_at === null
            && $this->interaction_deadline_at?->isFuture() === true;
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
