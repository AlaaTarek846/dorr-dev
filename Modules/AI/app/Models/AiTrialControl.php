<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiTrialControl extends Model
{
    public const TRIAL_ELIGIBLE = 'eligible';

    public const TRIAL_ACTIVE = 'active';

    public const TRIAL_ENDED = 'ended';

    public const ABUSE_CLEAR = 'clear';

    public const ABUSE_FLAGGED = 'flagged';

    public const ABUSE_BLOCKED = 'blocked';

    /**
     * @var string
     */
    protected $table = 'ai_trial_control';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'trial_status',
        'abuse_status',
        'abuse_reason',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
