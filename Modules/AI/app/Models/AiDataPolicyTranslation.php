<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI data policy's admin text (ai_data_policy_translations). */
class AiDataPolicyTranslation extends Model
{
    protected $fillable = ['ai_data_policy_id', 'locale', 'name', 'description'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiDataPolicy::class, 'ai_data_policy_id');
    }
}
