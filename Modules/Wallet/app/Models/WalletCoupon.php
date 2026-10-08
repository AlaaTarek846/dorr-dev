<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discount for the payment screen — a percentage or a fixed amount (docs/sports-plan.md §5.2). */
class WalletCoupon extends Model
{
    protected $fillable = [
        'code', 'owner_type', 'owner_id', 'country_id', 'kind', 'value', 'max_discount_minor', 'min_amount_minor', 'purposes',
        'starts_at', 'expires_at', 'usage_limit', 'used_count', 'source', 'source_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'purposes' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'value' => 'integer', 'max_discount_minor' => 'integer',
            'min_amount_minor' => 'integer', 'usage_limit' => 'integer', 'used_count' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** The discount on an amount, never more than the amount (or the cap). */
    public function discountOn(int $amountMinor): int
    {
        $d = $this->kind === 'percent' ? intdiv($amountMinor * min(100, $this->value), 100) : $this->value;
        if ($this->max_discount_minor !== null) {
            $d = min($d, $this->max_discount_minor);
        }

        return max(0, min($amountMinor, $d));
    }
}
