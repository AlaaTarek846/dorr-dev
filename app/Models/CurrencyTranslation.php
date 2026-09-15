<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyTranslation extends Model
{
    protected $fillable = ['currency_id', 'locale', 'name'];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
