<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBenchmarkResult extends Model
{
    use HasFactory;

    protected $table = 'ai_benchmark_results';

    protected $fillable = [
        'run_id',
        'case_id',
        'actual_response',
        'abstained',
        'citation_present',
        'correctness_score',
        'passed',
        'latency_ms',
        'estimated_cost',
        'failure_reason',
    ];

    protected $casts = [
        'abstained' => 'boolean',
        'citation_present' => 'boolean',
        'passed' => 'boolean',
        'correctness_score' => 'decimal:3',
        'estimated_cost' => 'decimal:6',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiBenchmarkRun::class, 'run_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(AiBenchmarkCase::class, 'case_id');
    }
}
