<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An A/B test (phase D): a hypothesis, variants, the measured results per variant and an honest conclusion. */
class MarketingExperiment extends Model
{
    public const METRICS = ['ctr', 'conversion_rate'];

    protected $fillable = [
        'uuid', 'project_id', 'content_item_id', 'name', 'hypothesis', 'success_metric', 'variants', 'results', 'status', 'conclusion',
        'started_at', 'ended_at', 'created_by_type', 'created_by_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $e) {
            $e->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['variants' => 'array', 'results' => 'array', 'conclusion' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
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
