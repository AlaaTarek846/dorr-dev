<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI language evaluation's name (ai_language_evaluation_translations). */
class AiLanguageEvaluationTranslation extends Model
{
    protected $fillable = ['ai_language_evaluation_id', 'locale', 'name'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AiLanguageEvaluation::class, 'ai_language_evaluation_id');
    }
}
