<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a pack of extra offers costs in one country, in the minor units of its currency (FL-L01, phase 2). */
class FreelanceOfferPackPrice extends Model
{
    protected $fillable = ['pack_id', 'country_id', 'price_minor', 'status'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'status' => 'boolean'];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(FreelanceOfferPack::class, 'pack_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
