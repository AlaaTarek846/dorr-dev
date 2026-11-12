<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of a site hosting plan's customer-facing text (ai_site_hosting_plan_translations). */
class AiSiteHostingPlanTranslation extends Model
{
    protected $fillable = ['ai_site_hosting_plan_id', 'locale', 'name', 'description'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiSiteHostingPlan::class, 'ai_site_hosting_plan_id');
    }
}
