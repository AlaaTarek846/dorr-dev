<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatReportUser extends Model
{
    public $timestamps = false;

    protected $fillable = ['report_id', 'participant_type', 'participant_id'];
}
