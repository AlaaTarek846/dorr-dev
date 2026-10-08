<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSafetyScan extends Model
{
    use HasFactory;

    const DECISION_PASSED = 'passed';

    const DECISION_BLOCKED = 'blocked';

    const DECISION_SANITIZED = 'sanitized';

    const DECISION_REVIEW_REQUIRED = 'review_required';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'owner_type',
        'owner_id',
        'target_type',
        'scan_type',
        'decision',
        'findings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'findings' => 'array',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
