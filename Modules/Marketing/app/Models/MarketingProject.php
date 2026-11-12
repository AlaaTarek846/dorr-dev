<?php

namespace Modules\Marketing\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A business the owner markets (U01). Its team is the members list (D2); the owner is a member with role `owner`. */
class MarketingProject extends Model
{
    use SoftDeletes;

    public const STAGES = ['idea', 'launch', 'growth'];

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'country_id', 'city_names', 'name', 'industry', 'stage', 'goals', 'budget_currency', 'status', 'lock_version'];

    protected static function booted(): void
    {
        static::creating(function (self $project) {
            $project->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['city_names' => 'array', 'goals' => 'array', 'lock_version' => 'integer', 'country_id' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(MarketingProjectProfile::class, 'project_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MarketingProjectMember::class, 'project_id');
    }

    public function researchRuns(): HasMany
    {
        return $this->hasMany(MarketingResearchRun::class, 'project_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(MarketingPlan::class, 'project_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(MarketingJob::class, 'project_id');
    }
}
