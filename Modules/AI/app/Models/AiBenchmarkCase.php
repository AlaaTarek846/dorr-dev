<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;

/**
 * One benchmark question of the AI quality bank (AiBenchmarkRunner sends it to a provider and scores the answer).
 * Has the countries list (status, trash) but no translation table: the question, the expected keywords and the
 * notes are test data and admin notes, not labels people read.
 *
 * Switched off or in the trash = never run. `$case->is_active` reads `status` (HasIsActiveAlias).
 */
class AiBenchmarkCase extends Model
{
    use HasFactory, HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    public const BEHAVIOR_ANSWER = 'answer';

    public const BEHAVIOR_ABSTAIN = 'abstain';

    public const BEHAVIOR_ASK_CLARIFICATION = 'ask_clarification';

    public const DIFFICULTY_EASY = 'easy';

    public const DIFFICULTY_HARD = 'hard';

    public const DIFFICULTY_ADVERSARIAL = 'adversarial';

    public const DIFFICULTY_INSUFFICIENT_INFO = 'insufficient_info';

    protected $table = 'ai_benchmark_cases';

    protected $fillable = [
        'domain_key',
        'country_code',
        'language',
        'task_type',
        'difficulty',
        'risk_level',
        'prompt',
        'expected_behavior',
        'expected_answer_keywords',
        'notes',
        'status',
        'is_active',
    ];

    protected $casts = [
        'expected_answer_keywords' => 'array',
        'status' => 'boolean',
    ];

    /** The scores this case got in past benchmark runs (deleting the case for good deletes them - cascade). */
    public function results(): HasMany
    {
        return $this->hasMany(AiBenchmarkResult::class, 'case_id');
    }
}
