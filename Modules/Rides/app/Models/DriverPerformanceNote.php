<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A note, a warning, praise or a rank change on a captain's record (D-258), and its one appeal (D-259). */
class DriverPerformanceNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['appealed_at' => 'datetime', 'answered_at' => 'datetime'];
    }
}
