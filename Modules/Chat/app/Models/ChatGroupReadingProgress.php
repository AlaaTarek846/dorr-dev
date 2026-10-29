<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** How far one student is with one reading item (GR-026). */
class ChatGroupReadingProgress extends Model
{
    protected $table = 'chat_group_reading_progress';

    protected $fillable = ['item_id', 'participant_id', 'status', 'pages_read', 'note'];
}
