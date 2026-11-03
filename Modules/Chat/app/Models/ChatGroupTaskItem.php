<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a task's checklist (GR-035). */
class ChatGroupTaskItem extends Model
{
    protected $fillable = ['task_id', 'text', 'done_at', 'done_by_participant_id', 'sort_order'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'sort_order' => 'integer'];
    }
}
