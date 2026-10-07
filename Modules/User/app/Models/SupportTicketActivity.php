<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;

/** One step in a ticket's status history: who moved it, to which status, when. */
class SupportTicketActivity extends Model
{
    protected $fillable = ['support_ticket_id', 'admin_id', 'actor', 'status'];

    protected function casts(): array
    {
        return ['status' => SupportTicketStatus::class];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
