<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI domain policy's admin text (ai_domain_policy_translations). */
class AiDomainPolicyTranslation extends Model
{
    protected $fillable = ['ai_domain_policy_id', 'locale', 'name', 'description'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiDomainPolicy::class, 'ai_domain_policy_id');
    }
}
