<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One charge of a subscription (FL-L01): the first purchase, an upgrade (the unused time of the old plan taken off) or a renewal — paid or failed. */
class FreelanceSubscriptionInvoice extends Model
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'uuid', 'subscription_id', 'freelancer_id', 'plan_id', 'period', 'kind', 'price_minor', 'credit_minor', 'amount_minor', 'currency_code', 'status',
        'failure_code', 'wallet_transaction_id', 'period_start', 'period_end', 'paid_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $i) => $i->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer', 'credit_minor' => 'integer', 'amount_minor' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'plan_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FreelanceSubscription::class, 'subscription_id');
    }
}
