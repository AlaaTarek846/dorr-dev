<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Language;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A language evaluation suite: a named record of how well the assistant answers in a language (or one of its
 * variants) - test-case count, pass rate, result and notes, entered by the admin. A catalog entity like Country:
 * the name lives in ai_language_evaluation_translations; the notes are free-text admin notes (not translated).
 *
 * Two different "statuses":
 * - `evaluation_status` = the evaluation's result: pending / running / passed / failed (the STATUS_* constants);
 * - `status` = the catalog on/off switch (filters, toggle, counts); deleting moves it to the trash.
 *
 * Back-compat (HasFlatTranslatedText): `$evaluation->name` reads the current language and flat text
 * (`create(['name' => ...])`) is written into every stored language; `$evaluation->is_active` reads `status`.
 */
class AiLanguageEvaluation extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    /** evaluation_status values (the result, not the on/off switch). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const EVALUATION_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PASSED, self::STATUS_FAILED];

    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'language_id',
        'variant_id',
        'test_case_count',
        'pass_rate',
        'evaluation_status',
        'evaluated_at',
        'notes',
        'status',
        'is_active',
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
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_language_evaluation_translations: drop the cached relations.
        static::saved(function (self $evaluation): void {
            $evaluation->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiLanguageEvaluationTranslation::class, 'ai_language_evaluation_id');
    }

    protected function translationModel(): string
    {
        return AiLanguageEvaluationTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name'];
    }

    // Root-cause fix (languages consolidation): used to belong to the
    // AI module's own now-removed "ai_languages" table - points at the
    // platform's single general Language model instead, matching the
    // languages_consolidation migrations.
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    /** withTrashed: the screen still names a variant that was moved to the trash. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'variant_id')->withTrashed();
    }
}
