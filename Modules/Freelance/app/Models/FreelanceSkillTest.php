<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** How the test of one skill is set (FL-L08): when it is on, how many questions an attempt draws, for how long, and what passes. */
class FreelanceSkillTest extends Model
{
    protected $fillable = ['freelance_skill_id', 'status', 'questions_count', 'minutes', 'pass_percent', 'retake_hours'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'questions_count' => 'integer', 'minutes' => 'integer', 'pass_percent' => 'integer', 'retake_hours' => 'integer'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FreelanceSkillQuestion::class, 'freelance_skill_id', 'freelance_skill_id');
    }
}
