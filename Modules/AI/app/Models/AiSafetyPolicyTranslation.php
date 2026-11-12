<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI safety policy's admin text (ai_safety_policy_translations). */
class AiSafetyPolicyTranslation extends Model
{
    protected $fillable = ['ai_safety_policy_id', 'locale', 'name', 'description'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSafetyPolicy::class, 'ai_safety_policy_id');
    }
}
