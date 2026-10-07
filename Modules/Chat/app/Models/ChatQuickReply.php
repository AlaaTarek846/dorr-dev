<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/**
 * A saved answer, typed as "/shortcut" in the composer (WhatsApp Business quick replies).
 */
class ChatQuickReply extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'shortcut', 'body'];

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'shortcut' => $this->shortcut,
            'body' => $this->body,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
