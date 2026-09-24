<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLanguageEvaluation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'language_id',
        'variant_id',
        'test_case_count',
        'pass_rate',
        'status',
        'evaluated_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_case_count' => 'integer',
            'pass_rate' => 'decimal:2',
            'evaluated_at' => 'datetime',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(AiLanguage::class, 'language_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'variant_id');
    }
}
