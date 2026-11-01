<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One go at a skill test (FL-L08). `questions` freezes what was drawn and in which option order, so marking is the same whatever the bank does later. */
class FreelanceSkillAttempt extends Model
{
    public const STARTED = 'started';

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $fillable = ['freelancer_id', 'freelance_skill_id', 'questions', 'status', 'score_percent', 'correct_count', 'pass_percent', 'started_at', 'expires_at', 'submitted_at'];

    protected function casts(): array
    {
        return [
            'questions' => 'array', 'score_percent' => 'integer', 'correct_count' => 'integer', 'pass_percent' => 'integer',
            'started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }
}
