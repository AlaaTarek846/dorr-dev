<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An immutable snapshot of a plan. Editing never changes a row here: it writes the next version. */
class MarketingPlanVersion extends Model
{
    protected $fillable = ['plan_id', 'version_no', 'structured_json', 'content_hash', 'assumption_set', 'critic', 'origin', 'created_by_type', 'created_by_id'];

    protected function casts(): array
    {
        return ['structured_json' => 'array', 'assumption_set' => 'array', 'critic' => 'array', 'version_no' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MarketingPlan::class, 'plan_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(MarketingFinancialScenario::class, 'plan_version_id');
    }
}
