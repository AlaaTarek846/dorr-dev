<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatStickerPackTranslation extends Model
{
    protected $fillable = ['chat_sticker_pack_id', 'locale', 'name'];
}
