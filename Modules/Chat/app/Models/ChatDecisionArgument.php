<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member's argument in a decision room (spec 153): for, against, or a note — on an option or the whole question. */
class ChatDecisionArgument extends Model
{
    public const STANCES = ['pro', 'con', 'note'];

    protected $fillable = ['decision_id', 'participant_id', 'option_id', 'stance', 'text'];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'participant_id');
    }
}
