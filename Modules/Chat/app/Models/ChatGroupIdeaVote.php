<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One member's vote for an idea (GR-044). */
class ChatGroupIdeaVote extends Model
{
    protected $fillable = ['idea_id', 'participant_id'];
}
