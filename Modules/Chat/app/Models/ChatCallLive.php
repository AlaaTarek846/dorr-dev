<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call broadcast live (spec 93). */
class ChatCallLive extends Model
{
    protected $fillable = ['uuid', 'call_id', 'conversation_id', 'started_by_participant_id', 'title', 'audience', 'token', 'status', 'viewers_peak', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'viewers_peak' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'started_by_participant_id');
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }
}
