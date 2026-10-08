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
        return $this->belongsTo(AiSiteHostingPlan::class, 'plan_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
