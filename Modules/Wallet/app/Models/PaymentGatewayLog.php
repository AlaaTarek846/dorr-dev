<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Wallet\Enums\PaymentGatewayLogEvent;

/**
 * Append-only audit trail of every interaction with a gateway — same
 * philosophy as wallet_transactions (docs/wallet-structure.md §9.2): a row is
 * written once and never touched.
 */
class PaymentGatewayLog extends Model
{
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_transaction_id',
        'provider_code',
        'event',
        'direction',
        'http_status',
        'request_payload',
        'response_payload',
        'gateway_status_reported',
        'external_reference',
        'triggered_by_type',
        'triggered_by_id',
    ];

    protected function casts(): array
    {
        return [
            'event' => PaymentGatewayLogEvent::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('payment_gateway_logs is append-only.'));
        static::deleting(fn () => throw new LogicException('payment_gateway_logs is append-only.'));
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
