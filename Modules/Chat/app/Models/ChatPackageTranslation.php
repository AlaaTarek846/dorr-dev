<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPackageTranslation extends Model
{
    protected $fillable = ['chat_package_id', 'locale', 'name', 'description'];
}
