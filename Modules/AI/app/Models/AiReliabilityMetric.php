<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReliabilityMetric extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'model_key',
        'intent_id',
        'region',
        'plan_id',
        'period_start',
        'period_end',
        'success_rate',
        'error_rate',
        'latency_p50_ms',
        'latency_p95_ms',
        'latency_p99_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'success_rate' => 'decimal:2',
            'error_rate' => 'decimal:2',
            'latency_p50_ms' => 'integer',
            'latency_p95_ms' => 'integer',
            'latency_p99_ms' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(AiIntent::class, 'intent_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'plan_id');
    }
}
