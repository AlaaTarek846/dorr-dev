<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A copy of one message as it was when the report was made.
 */
class ChatReportMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['report_id', 'message_id', 'sender_type', 'sender_id', 'type', 'body', 'attachments', 'sent_at'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
