<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One student at one session: present / absent / late / excused (GR-017). */
class ChatGroupAttendance extends Model
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    protected $table = 'chat_group_attendance';

    protected $fillable = ['session_id', 'participant_id', 'status', 'note', 'recorded_by_participant_id'];
}
