<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** The admin's date for one moment, one year, one country (or all): wins over any computed date. */
class ChatMomentDate extends Model
{
    protected $fillable = ['chat_moment_id', 'country_id', 'year', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date', 'year' => 'integer'];
    }
}
