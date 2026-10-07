<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiBenchmarkRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_benchmark_runs';

    protected $fillable = [
        'provider_id',
        'model_key',
        'status',
        'total_cases',
        'passed_cases',
        'abstained_cases',
        'pass_rate',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'pass_rate' => 'decimal:2',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(AiBenchmarkResult::class, 'run_id');
    }
}
