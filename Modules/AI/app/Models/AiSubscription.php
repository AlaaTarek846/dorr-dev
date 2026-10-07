<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSubscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'plan_id',
        'starts_at',
        'ends_at',
        'status',
        'auto_renew',
        'grace_ends_at',
        'renewal_reminder_sent_at',
        'grace_reminder_sent_at',
        'current_plan_price',
        'current_plan_duration_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'renewal_reminder_sent_at' => 'datetime',
            'grace_reminder_sent_at' => 'datetime',
            'auto_renew' => 'boolean',
            'current_plan_price' => 'decimal:2',
            'current_plan_duration_days' => 'integer',
        ];
    }

    /**
     * The User or Provider this subscription belongs to.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AiSubscriptionPayment::class, 'subscription_id');
    }

    /**
     * True the moment a renewal charge has failed at least once and the
     * 3-day grace window (docs: AiSubscriptionPurchaseService::renew())
     * has not passed yet - access stays on throughout.
     */
    public function isInGracePeriod(): bool
    {
        return $this->grace_ends_at !== null && $this->grace_ends_at->isFuture();
    }

    /**
     * A free/admin-assigned subscription with no billing period at all
     * (ends_at null, e.g. the trial row AiChatUsageGuard auto-creates) is
     * never a candidate for the renewal scheduler - there is nothing to
     * charge it for.
     */
    public function isBillable(): bool
    {
        return $this->ends_at !== null && (float) $this->current_plan_price > 0;
    }
}
