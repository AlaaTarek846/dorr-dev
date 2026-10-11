<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Models\ChatConversation;

/** A chat group made for going to an event together (spec 179); only its admins write after it. */
class DiscoverEventRoom extends Model
{
    protected $fillable = ['event_id', 'conversation_id', 'owner_type', 'owner_id', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DiscoverEvent::class, 'event_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
