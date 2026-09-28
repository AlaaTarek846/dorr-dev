<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatReportTypeTranslation extends Model
{
    protected $fillable = ['chat_report_type_id', 'locale', 'name'];
}
