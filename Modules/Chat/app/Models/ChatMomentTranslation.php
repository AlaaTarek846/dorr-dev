<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMomentTranslation extends Model
{
    protected $fillable = ['chat_moment_id', 'locale', 'name', 'greeting'];
}
