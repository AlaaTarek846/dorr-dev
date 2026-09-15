<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlagTranslation extends Model
{
    protected $fillable = ['flag_id', 'locale', 'name'];

    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }
}
