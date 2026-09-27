<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBlock extends Model
{
    protected $fillable = ['blocker_type', 'blocker_id', 'blocked_type', 'blocked_id'];
}
