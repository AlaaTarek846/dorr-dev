<?php

namespace Modules\AI\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPlanPrice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'country_id',
        'currency_id',
        'price',
        'original_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'plan_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Same savings-badge logic as AiPlan::discountPercent(), scoped to this
     * country's own price/original_price pair.
     */
    public function discountPercent(): ?int
    {
        if ($this->original_price === null || (float) $this->original_price <= (float) $this->price) {
            return null;
        }

        return (int) round((1 - ((float) $this->price / (float) $this->original_price)) * 100);
    }
}
