<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** A badge given to a member of a work group (GR-043). */
class ChatGroupBadge extends Model
{
    protected $fillable = ['conversation_id', 'participant_id', 'emoji', 'title', 'reason', 'given_by_participant_id'];
}
