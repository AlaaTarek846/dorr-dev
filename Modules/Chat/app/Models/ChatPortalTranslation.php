<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPortalTranslation extends Model
{
    protected $fillable = ['chat_portal_id', 'locale', 'name', 'description'];
}
