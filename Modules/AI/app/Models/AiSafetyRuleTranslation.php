<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI safety rule's name (ai_safety_rule_translations). */
class AiSafetyRuleTranslation extends Model
{
    protected $fillable = ['ai_safety_rule_id', 'locale', 'name'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiSafetyRule::class, 'ai_safety_rule_id');
    }
}
