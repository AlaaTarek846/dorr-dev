<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiArApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $table = 'ai_ar_approvals';

    protected $fillable = ['experience_id', 'experience_version_id', 'provider_id', 'status', 'customer_note', 'provider_note', 'quote_minor', 'currency', 'decided_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['provider_id' => 'integer', 'quote_minor' => 'integer', 'decided_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(AiArExperience::class, 'experience_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AiArExperienceVersion::class, 'experience_version_id');
    }
}
