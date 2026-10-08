<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class SupportHelpNodeTranslation extends Model
{
    protected $fillable = ['support_help_node_id', 'locale', 'title', 'answer'];
}
