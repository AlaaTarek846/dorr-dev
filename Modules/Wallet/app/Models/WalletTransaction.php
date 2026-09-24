<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Enums\WalletTransactionType;

/**
 * Immutable ledger row — docs/wallet-structure.md §2. Only
 * Modules\Wallet\Services\WalletService is allowed to create these (never
 * update or delete one). The update()/delete() guards below are a safety
 * net, not the primary enforcement — don't rely on catching the exception
 * as normal control flow.
 */
class WalletTransaction extends Model
{
    /**
     * No updated_at column at all — enforced at the schema level too.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'wallet_id',
        'operation_id',
        'direction',
        'bucket',
        'type',
        'amount_minor',
        'currency_id',
        'country_id',
        'balance_after_minor',
        'total_balance_after_minor',
        'reference_type',
        'reference_id',
        'counterparty_wallet_id',
        'reverses_transaction_id',
        'fee_rule_id',
        'fee_percent',
        'payment_transaction_id',
        'idempotency_key',
        'request_hash',
        'notes',
        'created_by_type',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => WalletDirection::class,
            'bucket' => WalletBucket::class,
            'type' => WalletTransactionType::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'total_balance_after_minor' => 'integer',
            'fee_percent' => 'decimal:4',
            'notes' => 'json',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('wallet_transactions is immutable — write a reversing row instead of updating one.');
        });

        static::deleting(function () {
            throw new LogicException('wallet_transactions is immutable — write a reversing row instead of deleting one.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function counterpartyWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * The row that reversed this one, if any — found by reference, not by
     * mutating this row (see class docblock).
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function isReversed(): bool
    {
        return $this->reversedBy()->exists();
    }
}
