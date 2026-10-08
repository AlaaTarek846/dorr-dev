<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSafetyEvent extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'request_id',
        'safety_policy_id',
        'safety_rule_id',
        'action_taken',
        'reason',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSafetyPolicy::class, 'safety_policy_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiSafetyRule::class, 'safety_rule_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
