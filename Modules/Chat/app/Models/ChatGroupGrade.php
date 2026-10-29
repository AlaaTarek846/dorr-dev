<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One student's mark on a homework or a test (GR-014, GR-015) — seen by that student and those who mark. */
class ChatGroupGrade extends Model
{
    protected $fillable = ['gradable_type', 'gradable_id', 'participant_id', 'score', 'feedback', 'graded_by_participant_id', 'graded_at'];

    protected function casts(): array
    {
        return ['score' => 'float', 'graded_at' => 'datetime'];
    }
}
