<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/** What an offer said before one change (FL-302). Written once, never changed. */
class FreelanceOfferRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['offer_id', 'number', 'price_minor', 'duration_days', 'details', 'commission_minor', 'net_minor'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
