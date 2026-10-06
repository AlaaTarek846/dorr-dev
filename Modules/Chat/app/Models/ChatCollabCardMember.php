<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/** Someone signing a group card — their words, voice, photo (spec 163). */
class ChatCollabCardMember extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = ['collab_card_id', 'member_type', 'member_id', 'text', 'contributed_at'];

    protected function casts(): array
    {
        return ['contributed_at' => 'datetime'];
    }
}
