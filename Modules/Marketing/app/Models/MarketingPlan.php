<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Marketing\Enums\PlanStatus;

/** Plan states (spec 05): DRAFT, READY_FOR_REVIEW, APPROVED, then SUPERSEDED / ARCHIVED. */
class MarketingPlan extends Model
{
    public const TYPES = ['business', 'marketing', 'gtm'];

    protected $fillable = ['uuid', 'project_id', 'plan_type', 'status', 'current_version_id', 'created_by_type', 'created_by_id'];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            $plan->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['status' => PlanStatus::class];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MarketingPlanVersion::class, 'plan_id')->orderBy('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MarketingPlanVersion::class, 'current_version_id');
    }
}
