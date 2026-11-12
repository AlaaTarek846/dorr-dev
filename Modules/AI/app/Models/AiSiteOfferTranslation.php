<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of a website-builder offer's customer-facing text (ai_site_offer_translations). */
class AiSiteOfferTranslation extends Model
{
    protected $fillable = ['ai_site_offer_id', 'locale', 'name', 'description'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(AiSiteOffer::class, 'ai_site_offer_id');
    }
}
