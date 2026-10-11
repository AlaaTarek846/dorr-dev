<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreement card (spec 154): terms the parties approve, version by version. A record only —
 * nothing operational (payment, booking) happens from it.
 */
class ChatAgreement extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'source_message_id', 'card_message_id', 'created_by_participant_id', 'title', 'terms', 'parties', 'approvals', 'version', 'status', 'agreed_at'];

    protected function casts(): array
    {
        return ['terms' => 'array', 'parties' => 'array', 'approvals' => 'array', 'version' => 'integer', 'agreed_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'created_by_participant_id');
    }

    /** @return list<int> */
    public function partyIds(): array
    {
        return array_map('intval', (array) $this->parties);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'card_message_id');
    }
}
