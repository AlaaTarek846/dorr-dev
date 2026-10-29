<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** A question in the teacher's bank (GR-016), reused in tests. */
class ChatGroupBankQuestion extends Model
{
    protected $table = 'chat_group_question_bank';

    protected $fillable = ['conversation_id', 'created_by_participant_id', 'subject', 'unit', 'difficulty', 'type', 'text', 'options', 'correct', 'points'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct' => 'array', 'points' => 'float'];
    }
}
