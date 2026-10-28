<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money for one accepted design: charged from the customer's wallet, held by the platform, released to the provider after delivery. */
class AiArOrder extends Model
{
    public const PAID = 'paid';

    public const DELIVERED = 'delivered';

    public const DISPUTED = 'disputed';

    public const RELEASED = 'released';

    public const REFUNDED = 'refunded';

    protected $table = 'ai_ar_orders';

    protected $fillable = [
        'experience_id', 'approval_id', 'customer_type', 'customer_id', 'provider_id', 'country_id', 'amount_minor', 'commission_percent', 'commission_minor',
        'provider_net_minor', 'currency', 'status', 'delivery_note', 'dispute_reason', 'resolution_note', 'charge_operation_id',
        'paid_at', 'delivered_at', 'release_at', 'released_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer', 'provider_id' => 'integer', 'amount_minor' => 'integer', 'commission_minor' => 'integer', 'provider_net_minor' => 'integer',
            'commission_percent' => 'float', 'paid_at' => 'datetime', 'delivered_at' => 'datetime', 'release_at' => 'datetime', 'released_at' => 'datetime', 'refunded_at' => 'datetime',
        ];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(AiArExperience::class, 'experience_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AiArApproval::class, 'approval_id');
    }
}
