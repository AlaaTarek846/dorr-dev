<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatCategoryTranslation extends Model
{
    protected $fillable = ['chat_category_id', 'locale', 'name'];
}
