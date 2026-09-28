<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppFontTranslation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'mobile_app_font_id',
        'locale',
        'name',
    ];

    public function font(): BelongsTo
    {
        return $this->belongsTo(MobileAppFont::class, 'mobile_app_font_id');
    }
}
