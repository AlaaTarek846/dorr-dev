<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI routing policy's name (ai_routing_policy_translations). */
class AiRoutingPolicyTranslation extends Model
{
    protected $fillable = ['ai_routing_policy_id', 'locale', 'name'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'ai_routing_policy_id');
    }
}
