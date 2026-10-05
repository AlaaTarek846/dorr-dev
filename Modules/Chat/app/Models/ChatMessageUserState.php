<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's own marks on a message: starred, deleted "for me", or a view-once opened.
 */
class ChatMessageUserState extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'participant_id', 'starred_at', 'read_later_at', 'follow_up_at', 'deleted_at', 'opened_at'];

    protected function casts(): array
    {
        return ['starred_at' => 'datetime', 'read_later_at' => 'datetime', 'follow_up_at' => 'datetime', 'deleted_at' => 'datetime', 'opened_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
