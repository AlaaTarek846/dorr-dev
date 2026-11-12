<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI security policy's admin text (ai_security_policy_translations). */
class AiSecurityPolicyTranslation extends Model
{
    protected $fillable = ['ai_security_policy_id', 'locale', 'name', 'description'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSecurityPolicy::class, 'ai_security_policy_id');
    }
}
