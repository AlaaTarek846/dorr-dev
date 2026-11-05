<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A ticket about a move (REQ-062 → REQ-064, REQ-103): eight kinds; it goes to the establishment first and only then,
 * with a reason, to Dorr; the establishment's answer never closes it — the customer does, or a person with a reason.
 */
class MovingTicket extends Model
{
    public const TYPES = ['suggestion', 'inquiry', 'complaint', 'safety', 'inventory', 'delay', 'financial', 'note'];

    protected $table = 'moving_tickets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['escalated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MovingRequest::class, 'request_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MovingTicketMessage::class, 'ticket_id')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['resolved', 'unresolved'], true);
    }
}
