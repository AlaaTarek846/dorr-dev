<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Support\ParticipantType;

/**
 * Someone opened a group's invite link while the group asks admins to approve new members.
 */
class ChatGroupJoinRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['conversation_id', 'requester_type', 'requester_id', 'status', 'decided_by_participant_id', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeBy(Builder $query, Model $account): Builder
    {
        return $query->where('requester_type', ParticipantType::aliasFor($account))->where('requester_id', $account->getKey());
    }

    public function requester(): ?Model
    {
        return ParticipantType::modelClassFor($this->requester_type)::query()->find($this->requester_id);
    }

    /**
     * `type:id`, the same shape the directory and the wire use.
     */
    public function requesterKey(): string
    {
        return $this->requester_type.':'.$this->requester_id;
    }
}
