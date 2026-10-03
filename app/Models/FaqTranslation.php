<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqTranslation extends Model
{
    protected $fillable = ['faq_id', 'locale', 'question', 'answer'];

    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class);
    }
}
