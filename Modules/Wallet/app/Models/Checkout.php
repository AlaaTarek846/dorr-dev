<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Wallet\Enums\CheckoutStatus;
use Modules\Wallet\Support\OwnerType;

/**
 * One visit to the payment screen: what is being bought (a registered purpose + its reference),
 * for how much, and how it was paid. See CheckoutService.
 */
class Checkout extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'owner_type',
        'owner_id',
        'country_id',
        'currency_id',
        'purpose',
        'reference',
        'title',
        'subtitle',
        'amount_minor',
        'coupon_id',
        'discount_minor',
        'status',
        'paid_via',
        'payment_transaction_id',
        'wallet_operation_id',
        'failure_reason',
        'expires_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'reference' => 'array',
            'amount_minor' => 'integer',
            'discount_minor' => 'integer',
            'status' => CheckoutStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * Deliberately not an Eloquent morphTo() — see OwnerType's docblock.
     */
    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === OwnerType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }

    public function referenceValue(string $key): mixed
    {
        return ($this->reference ?? [])[$key] ?? null;
    }

    /** What's charged: the price less a coupon's discount. */
    public function payableMinor(): int
    {
        return max(0, (int) $this->amount_minor - (int) $this->discount_minor);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(WalletCoupon::class, 'coupon_id');
    }
}
