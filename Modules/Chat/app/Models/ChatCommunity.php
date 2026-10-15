<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A community (WhatsApp list 93–98) — see CommunityService.
 *
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $creator_type
 * @property int $creator_id
 * @property int $announcement_conversation_id
 */
class ChatCommunity extends Model
{
    use SoftDeletes;

    protected $table = 'chat_communities';

    protected $fillable = ['uuid', 'name', 'description', 'creator_type', 'creator_id', 'announcement_conversation_id'];

    protected static function booted(): void
    {
        static::creating(function (self $community) {
            $community->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'announcement_conversation_id');
    }

    /** Every group in it, the announcements group included. */
    public function groups(): HasMany
    {
        return $this->hasMany(ChatGroup::class, 'community_id');
    }
}
