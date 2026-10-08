<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class SupportQuickReplyTranslation extends Model
{
    protected $fillable = ['support_quick_reply_id', 'locale', 'title', 'body'];
}
