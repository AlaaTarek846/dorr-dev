<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FS-705: one feature's value on one plan (the catalog is `config('freelance.plan_features')`, read through `PlanFeatures`). `value` is
 * read by the feature's type: a switch is 0/1, a level 0..max, a limit a number (null = no cap), a fee discount basis points off.
 * No row means the feature's "not given" value - the plan follows the country's policy. The features that were columns of the plan
 * before FS-705 (offers, commission, badge, boost, analytics, CRM, services, team room) stay in those columns and have no row here.
 */
class FreelancePlanFeature extends Model
{
    protected $fillable = ['plan_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['plan_id' => 'integer', 'value' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'plan_id');
    }
}
