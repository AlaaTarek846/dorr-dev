<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** "Tell me when CPA goes above X over the last 7 days" (phase D). It only tells; it never changes spending. */
class MarketingAlertRule extends Model
{
    public const METRICS = ['spend', 'cpa', 'roas', 'ctr', 'conversions'];

    protected $fillable = [
        'uuid', 'project_id', 'metric', 'comparison', 'threshold', 'window_days', 'channel', 'active', 'last_triggered_at', 'last_value',
        'created_by_type', 'created_by_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['threshold' => 'float', 'last_value' => 'float', 'active' => 'boolean', 'window_days' => 'integer', 'last_triggered_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }
}
