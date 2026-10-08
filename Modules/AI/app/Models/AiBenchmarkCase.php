<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiBenchmarkCase extends Model
{
    use HasFactory;

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
        'is_active',
    ];

    protected $casts = [
        'expected_answer_keywords' => 'array',
        'is_active' => 'boolean',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(AiBenchmarkResult::class, 'case_id');
    }
}
