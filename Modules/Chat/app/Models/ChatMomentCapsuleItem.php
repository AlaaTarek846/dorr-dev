<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/** A copy of a message I kept in a capsule — its text and its files. */
class ChatMomentCapsuleItem extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = ['capsule_id', 'type', 'text', 'sender_name', 'note', 'source_message_id', 'original_at'];

    protected function casts(): array
    {
        return ['original_at' => 'datetime'];
    }
}
