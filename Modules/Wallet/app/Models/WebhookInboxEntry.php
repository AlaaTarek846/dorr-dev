<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Wallet\Enums\WebhookInboxStatus;

class WebhookInboxEntry extends Model
{
    protected $table = 'webhook_inbox';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_method_id',
        'payment_transaction_id',
        'provider_code',
        'event_id',
        'valid_signature',
        'payload',
        'payload_hash',
        'status',
        'attempts',
        'error',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_signature' => 'boolean',
            'payload' => 'array',
            'status' => WebhookInboxStatus::class,
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
