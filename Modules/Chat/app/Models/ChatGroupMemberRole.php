<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member holding one role — in the whole group (`space_id` null) or in one space (GR-007). */
class ChatGroupMemberRole extends Model
{
    protected $fillable = ['role_id', 'participant_id', 'space_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(ChatGroupRole::class, 'role_id');
    }
}
