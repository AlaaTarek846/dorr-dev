<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A long AI task (research or plan generation): what the apps show while it runs and why it stopped. */
class MarketingJob extends Model
{
    public const QUEUED = 'QUEUED';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const PARTIAL = 'PARTIAL';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const FINISHED = [self::COMPLETED, self::PARTIAL, self::FAILED, self::CANCELLED];

    protected $fillable = ['uuid', 'project_id', 'job_type', 'subject_type', 'subject_id', 'status', 'progress_pct', 'error_code', 'retry_count', 'payload', 'requested_by_type', 'requested_by_id', 'started_at', 'finished_at'];

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'progress_pct' => 'integer', 'retry_count' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }
}
