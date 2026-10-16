<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the conversation on a case; an `internal` one is an admin's note the reporter never sees. */
class RideCaseMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }
}
