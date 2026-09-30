<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One ticked option of a poll by one member (several rows when the poll allows many answers).
 */
class ChatPollVote extends Model
{
    protected $fillable = ['message_id', 'participant_id', 'option_id'];
}
