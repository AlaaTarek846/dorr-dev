<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThemeTranslation extends Model
{
    protected $fillable = ['chat_theme_id', 'locale', 'name'];
}
