<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI language variant's name (ai_language_variant_translations). */
class AiLanguageVariantTranslation extends Model
{
    protected $fillable = ['ai_language_variant_id', 'locale', 'name'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'ai_language_variant_id');
    }
}
