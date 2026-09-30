<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalPageTranslation extends Model
{
    protected $fillable = ['legal_page_id', 'locale', 'content'];

    public function legalPage(): BelongsTo
    {
        return $this->belongsTo(LegalPage::class);
    }
}