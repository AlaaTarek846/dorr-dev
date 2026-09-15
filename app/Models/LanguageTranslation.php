<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanguageTranslation extends Model
{
    protected $fillable = ['language_id', 'locale', 'name'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
