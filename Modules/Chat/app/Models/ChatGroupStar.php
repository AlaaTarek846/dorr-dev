<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** A star of the week (GR-025): a student, the week, why. */
class ChatGroupStar extends Model
{
    protected $fillable = ['conversation_id', 'participant_id', 'week_of', 'reason', 'awarded_by_participant_id'];

    protected function casts(): array
    {
        return ['week_of' => 'date'];
    }
}
