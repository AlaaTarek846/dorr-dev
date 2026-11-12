<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;

class MovingTicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'moving_ticket_messages';

    protected $fillable = ['ticket_id', 'author_type', 'author_id', 'body'];
}
