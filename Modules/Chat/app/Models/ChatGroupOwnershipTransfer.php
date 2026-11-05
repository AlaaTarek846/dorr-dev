<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The owner offering an educational / work group to another member, until they answer (GR-005). */
class ChatGroupOwnershipTransfer extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['conversation_id', 'from_participant_id', 'to_participant_id', 'status', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'from_participant_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'to_participant_id');
    }
}
