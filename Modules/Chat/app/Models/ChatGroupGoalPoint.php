<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** Points given to a member in a team competition (GR-042). */
class ChatGroupGoalPoint extends Model
{
    protected $fillable = ['goal_id', 'participant_id', 'points', 'note', 'given_by_participant_id'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
