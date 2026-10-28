<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something the person explicitly asked DORR AI to remember (spec 142) — see AiMemoryService.
 */
class AiMemory extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'content',
        'source_conversation_id',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'source_conversation_id');
    }
}
