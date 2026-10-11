<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Admin;

class SupportMessage extends Model
{
    public const SENDER_USER = 'user';

    public const SENDER_SUPPORT = 'support';

    /** An automatic reply (acknowledgement, away note, FAQ answer) — never in an agent's name. */
    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'user_id',
        'admin_id',
        'support_ticket_id',
        'sender',
        'body',
        'image_path',
        'is_auto',
        'auto_kind',
    ];

    protected function casts(): array
    {
        return ['is_auto' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
