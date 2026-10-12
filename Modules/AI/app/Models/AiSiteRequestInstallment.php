<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment of the quote. trigger: on_accept (taken when the customer accepts), on_milestone (becomes payable when
 * that deliverable is handed over) or on_delivery (the final one, payable when the finished site is delivered).
 */
class AiSiteRequestInstallment extends Model
{
    public const PENDING = 'pending';

    public const DUE = 'due';

    public const PAID = 'paid';

    public const ON_ACCEPT = 'on_accept';

    public const ON_MILESTONE = 'on_milestone';

    public const ON_DELIVERY = 'on_delivery';

    protected $table = 'ai_site_request_installments';

    protected $fillable = ['request_id', 'title', 'amount', 'trigger', 'milestone_id', 'status', 'due_at', 'paid_at', 'wallet_operation_id', 'sort_order'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'due_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
