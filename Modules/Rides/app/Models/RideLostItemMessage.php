<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A message between the rider and the captain about a lost item — no numbers exchanged (D-296). */
class RideLostItemMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
