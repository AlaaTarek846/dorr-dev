<?php

namespace Modules\AI\Models;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSiteHostingPlanPrice extends Model
{
    protected $table = 'ai_site_hosting_plan_prices';

    protected $fillable = ['plan_id', 'country_id', 'currency_id', 'price'];

    public function plan(): BelongsTo
    {
        // withTrashed: a plan in the trash keeps its prices (restore brings it back as it was).
        return $this->belongsTo(AiSiteHostingPlan::class, 'plan_id')->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
