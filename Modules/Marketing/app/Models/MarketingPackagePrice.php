<?php

namespace Modules\Marketing\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPackagePrice extends Model
{
    protected $fillable = ['package_id', 'country_id', 'price_minor', 'status'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'country_id' => 'integer', 'status' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MarketingPackage::class, 'package_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
