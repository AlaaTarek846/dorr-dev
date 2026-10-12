<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/** One deliverable in the schedule ("home page ready on day 5"). */
class AiSiteRequestMilestone extends Model
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const DELIVERED = 'delivered';

    protected $table = 'ai_site_request_milestones';

    protected $fillable = ['request_id', 'title', 'description', 'due_days', 'due_at', 'status', 'delivered_at', 'sort_order'];

    protected function casts(): array
    {
        return ['due_days' => 'integer', 'due_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
