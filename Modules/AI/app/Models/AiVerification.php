<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVerification extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PASSED = 'passed';

    public const STATUS_NEEDS_CORRECTION = 'needs_correction';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABSTAINED = 'abstained';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'attempt_number',
        'verifier_provider_id',
        'draft_content',
        'claims',
        'issues',
        'supported_claims_ratio',
        'completeness_score',
        'evidence_strength',
        'confidence_score',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'claims' => 'array',
            'issues' => 'array',
            'supported_claims_ratio' => 'float',
            'completeness_score' => 'float',
            'evidence_strength' => 'float',
            'confidence_score' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    public function verifierProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'verifier_provider_id');
    }
}
