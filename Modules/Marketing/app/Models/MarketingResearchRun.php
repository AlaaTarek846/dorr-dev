<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Research Job states (spec 05): QUEUED, RUNNING, EVIDENCE_CHECK, then COMPLETED / PARTIAL / FAILED / CANCELLED. */
class MarketingResearchRun extends Model
{
    public const KINDS = ['market', 'competitor', 'audience'];

    public const EVIDENCE_CHECK = 'EVIDENCE_CHECK';

    protected $fillable = ['uuid', 'project_id', 'kind', 'status', 'query_scope', 'max_sources', 'summary', 'gaps', 'quality_score', 'error_code', 'started_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(function (self $run) {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['query_scope' => 'array', 'gaps' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'quality_score' => 'integer', 'max_sources' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(MarketingSource::class, 'research_run_id')->orderBy('id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(MarketingFinding::class, 'research_run_id')->orderBy('id');
    }
}
