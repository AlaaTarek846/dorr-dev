<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiUsageSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'subscription_id',
        'organization_id',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AiSubscription::class, 'subscription_id');
    }
}
