<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per subscription money event - see the migration's docblock for
 * why this exists independently of wallet_transactions.
 */
class AiSubscriptionPayment extends Model
{
    public const TYPE_INITIAL = 'initial';

    public const TYPE_RENEWAL = 'renewal';

    public const TYPE_UPGRADE = 'upgrade';

    public const TYPE_DOWNGRADE = 'downgrade';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id',
        'plan_id',
        'owner_type',
        'owner_id',
        'type',
        'status',
        'amount',
        'currency',
        'wallet_operation_id',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AiSubscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'plan_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
