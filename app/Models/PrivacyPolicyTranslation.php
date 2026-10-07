<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyPolicyTranslation extends Model
{
    protected $fillable = ['privacy_policy_id', 'locale', 'content'];

    public function privacyPolicy(): BelongsTo
    {
        return $this->belongsTo(PrivacyPolicy::class);
    }
}
