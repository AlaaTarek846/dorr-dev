<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;

/**
 * The one wallet table allowed to be UPDATEd — a hold is a transitional
 * state (active → captured/released/expired) until it reaches a final
 * status, after which it locks like everything else (guard below).
 */
class WalletHold extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'wallet_id',
        'bucket',
        'amount_minor',
        'status',
        'captured_amount_minor',
        'reference_type',
        'reference_id',
        'reason_code',
        'expires_at',
        'idempotency_key',
        'wallet_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'bucket' => WalletBucket::class,
            'status' => WalletHoldStatus::class,
            'amount_minor' => 'integer',
            'captured_amount_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $hold) {
            $original = $hold->getOriginal('status');
            $originalStatus = $original instanceof WalletHoldStatus ? $original : WalletHoldStatus::from((string) $original);

            if ($originalStatus->isFinal()) {
                throw new LogicException("wallet_holds #{$hold->id} is already {$originalStatus->value} and can't be changed further.");
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
