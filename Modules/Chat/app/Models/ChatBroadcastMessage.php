<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One broadcast I sent: what it was, and the messages it became (one per recipient). */
class ChatBroadcastMessage extends Model
{
    protected $fillable = ['list_id', 'type', 'body', 'message_ids', 'sent_count', 'skipped_count'];

    protected function casts(): array
    {
        return ['message_ids' => 'array', 'sent_count' => 'integer', 'skipped_count' => 'integer'];
    }
}
