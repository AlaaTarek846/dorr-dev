<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A term, subgroup or project inside an educational / work group (GR-007, decision D2): its own
 * group chat (`conversation_id`) with its own members, all of them members of the main group.
 */
class ChatGroupSpace extends Model
{
    public const TYPES = ['term', 'subgroup', 'project'];

    protected $fillable = ['parent_conversation_id', 'conversation_id', 'type', 'starts_on', 'ends_on', 'closed_at', 'sort_order'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'parent_conversation_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
