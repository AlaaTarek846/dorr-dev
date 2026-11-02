<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A parent linked to one student of an educational group (GR-018, decision D8). The student makes
 * the code, the parent enters it, the teacher approves. A parent is never a member of the chat.
 */
class ChatGroupParentLink extends Model
{
    public const MAX_PER_STUDENT = 4;

    protected $fillable = ['uuid', 'conversation_id', 'student_participant_id', 'parent_type', 'parent_id', 'code', 'code_expires_at', 'status', 'decided_by_participant_id', 'decided_at'];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function student()
    {
        return $this->belongsTo(ChatParticipant::class, 'student_participant_id');
    }

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
