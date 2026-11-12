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

    /** withTrashed: the log still names a policy that was moved to the trash. */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSafetyPolicy::class, 'safety_policy_id')->withTrashed();
    }

    /** withTrashed: the log still names a rule that was moved to the trash. */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiSafetyRule::class, 'safety_rule_id')->withTrashed();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
