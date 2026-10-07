<?php

namespace Modules\AI\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSiteOffer extends Model
{
    protected $table = 'ai_site_offers';

    protected $fillable = ['name', 'code', 'description', 'generations_included', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['generations_included' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(AiSitePurchase::class, 'offer_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AiSiteOfferPrice::class, 'offer_id');
    }

    /**
     * @return array{price: float, currency: string}|null  null = not sold in this country
     */
    public function priceFor(?Country $country): ?array
    {
        if ($country === null) {
            return null;
        }

        $row = $this->prices()->where('country_id', $country->id)->with('currency')->first();

        if ($row === null || $row->currency === null) {
            return null;
        }

        return ['price' => (float) $row->price, 'currency' => (string) $row->currency->code];
    }
}
