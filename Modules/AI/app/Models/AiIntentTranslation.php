<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI intent's admin text (ai_intent_translations). */
class AiIntentTranslation extends Model
{
    protected $fillable = ['ai_intent_id', 'locale', 'name', 'description'];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(AiIntent::class, 'ai_intent_id');
    }
}
