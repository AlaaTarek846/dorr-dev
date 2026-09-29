<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plain model (not a Pivot) since it carries its own data beyond the two
 * foreign keys (per-country min/max override, status) — docs/wallet-structure.md §8.
 */
class PaymentMethodCountry extends Model
{
    protected $table = 'payment_method_country';

    /**
     * @var list<string>
     */
    protected $fillable = ['payment_method_id', 'country_id', 'min_amount_minor', 'max_amount_minor', 'status'];

    protected function casts(): array
    {
        return [
            'min_amount_minor' => 'integer',
            'max_amount_minor' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
