<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** The case's own history: opened, taken, asked for more, decided, appealed. Append-only. */
class RideCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
