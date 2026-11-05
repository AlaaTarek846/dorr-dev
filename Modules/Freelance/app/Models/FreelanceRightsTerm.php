<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The standard text of one rights model (FL-L13) for a country, in Arabic and English. A row with no country is the text for every
 * country that has none of its own. An agreement keeps the text of the day it was made, so editing a row never changes one.
 */
class FreelanceRightsTerm extends Model
{
    protected $fillable = ['country_id', 'model', 'title_ar', 'title_en', 'body_ar', 'body_en', 'version', 'active'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'active' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
