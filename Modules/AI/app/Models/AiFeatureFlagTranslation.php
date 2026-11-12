<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI feature flag's admin description (ai_feature_flag_translations). */
class AiFeatureFlagTranslation extends Model
{
    protected $fillable = ['ai_feature_flag_id', 'locale', 'description'];

    public function flag(): BelongsTo
    {
        return $this->belongsTo(AiFeatureFlag::class, 'ai_feature_flag_id');
    }
}
