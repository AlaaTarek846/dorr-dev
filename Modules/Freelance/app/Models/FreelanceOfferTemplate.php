<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A text a freelancer keeps to start an offer from (FL-304). */
class FreelanceOfferTemplate extends Model
{
    protected $fillable = ['freelancer_id', 'title', 'details'];

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
