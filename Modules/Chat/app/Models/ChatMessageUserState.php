<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's own marks on a message: starred, or deleted "for me".
 */
class ChatMessageUserState extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'participant_id', 'starred_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['starred_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
