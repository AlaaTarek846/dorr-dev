<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBroadcastListMember extends Model
{
    protected $fillable = ['list_id', 'participant_type', 'participant_id'];

    public function key(): string
    {
        return $this->participant_type.':'.$this->participant_id;
    }
}
