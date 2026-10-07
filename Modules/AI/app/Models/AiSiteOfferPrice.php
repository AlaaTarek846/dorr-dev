<?php

namespace Modules\AI\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSiteOfferPrice extends Model
{
    protected $table = 'ai_site_offer_prices';

    protected $fillable = ['offer_id', 'country_id', 'currency_id', 'price'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(AiSiteOffer::class, 'offer_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Currency::class);
    }
}
