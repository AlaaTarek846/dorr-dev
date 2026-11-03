<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A viewer's reward (WE-033): waits its time, then goes into the wallet's reward bucket. */
class Reward extends Model
{
    public const VIEW = 'view';

    public const INTERACTION = 'interaction';

    protected $table = 'we_rewards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'available_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
