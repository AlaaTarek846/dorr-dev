<?php

namespace Modules\AI\Models;

use App\Models\Language;
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

    // Root-cause fix (languages consolidation): used to belong to the
    // AI module's own now-removed "ai_languages" table - points at the
    // platform's single general Language model instead, matching the
    // languages_consolidation migrations.
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'variant_id');
    }
}
