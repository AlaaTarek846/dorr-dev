<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\WalletNumber;

/**
 * Balances are a stored projection (Hybrid model, wallet-plan.md §11) that
 * only ever changes through Modules\Wallet\Services\WalletService — never
 * write to *_minor columns directly from anywhere else.
 */
class Wallet extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'wallet_number',
        'owner_type',
        'owner_id',
        'country_id',
        'currency_id',
        'withdrawable_minor',
        'spend_only_minor',
        'held_withdrawable_minor',
        'held_spend_only_minor',
        'status',
        'last_reconciled_at',
    ];

    protected static function booted(): void
    {
        // Every wallet has a public number from the moment it exists — the only
        // place wallets are created is WalletService::firstOrCreateWallet().
        static::creating(function (self $wallet) {
            if ($wallet->wallet_number === null) {
                do {
                    $number = WalletNumber::generate();
                } while (static::query()->where('wallet_number', $number)->exists());

                $wallet->wallet_number = $number;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'withdrawable_minor' => 'integer',
            'spend_only_minor' => 'integer',
            'held_withdrawable_minor' => 'integer',
            'held_spend_only_minor' => 'integer',
            'status' => 'boolean',
            'last_reconciled_at' => 'datetime',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /**
     * Deliberately not an Eloquent morphTo() — see OwnerType's docblock.
     */
    public function owner(): ?\Illuminate\Database\Eloquent\Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    public function balanceMinor(WalletBucket $bucket): int
    {
        return $bucket === WalletBucket::Withdrawable ? $this->withdrawable_minor : $this->spend_only_minor;
    }

    public function heldMinor(WalletBucket $bucket): int
    {
        return $bucket === WalletBucket::Withdrawable ? $this->held_withdrawable_minor : $this->held_spend_only_minor;
    }

    /**
     * What's actually free to spend/withdraw/hold right now — the balance
     * minus whatever active holds have already reserved.
     */
    public function availableMinor(WalletBucket $bucket): int
    {
        return $this->balanceMinor($bucket) - $this->heldMinor($bucket);
    }

    public function totalBalanceMinor(): int
    {
        return $this->withdrawable_minor + $this->spend_only_minor;
    }
}
