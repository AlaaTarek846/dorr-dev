<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Support\OwnerType;

/**
 * Where a gateway payment currently stands. `requested_amount_minor` is the
 * only amount a wallet is ever credited from — never a figure the gateway
 * reports back (docs/wallet-structure.md §9.3, invariant #5).
 */
class PaymentTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'payment_method_id',
        'owner_type',
        'owner_id',
        'wallet_id',
        'country_id',
        'currency_id',
        'requested_amount_minor',
        'status',
        'gateway_reference',
        'gateway_invoice_id',
        'redirect_url',
        'fee_rule_id',
        'fee_percent',
        'quoted_net_amount_minor',
        'quoted_bonus_amount_minor',
        'raw_request',
        'raw_response',
        'gateway_context',
        'failure_reason',
        'expires_at',
        'reconciliation_attempts',
        'last_reconciled_at',
        'idempotency_key',
        'request_hash',
        'processed_at',
    ];

    /**
     * gateway_context holds live gateway state (e.g. URPay's security token).
     *
     * @var list<string>
     */
    protected $hidden = ['gateway_context'];

    protected function casts(): array
    {
        return [
            'status' => PaymentTransactionStatus::class,
            'requested_amount_minor' => 'integer',
            'fee_percent' => 'decimal:4',
            'quoted_net_amount_minor' => 'integer',
            'quoted_bonus_amount_minor' => 'integer',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'gateway_context' => 'encrypted:array',
            'expires_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'processed_at' => 'datetime',
            'reconciliation_attempts' => 'integer',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(WalletFeeRule::class, 'fee_rule_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentGatewayLog::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Deliberately not an Eloquent morphTo() — see OwnerType's docblock.
     */
    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    /**
     * Fee actually kept by the platform on this payment (0 for a bonus/no rule).
     */
    public function quotedFeeMinor(): int
    {
        if ($this->quoted_net_amount_minor === null || $this->quoted_bonus_amount_minor > 0) {
            return 0;
        }

        return max(0, $this->requested_amount_minor - $this->quoted_net_amount_minor);
    }
}
