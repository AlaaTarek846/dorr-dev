<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language's name of a skill, with its cleaned form used to catch duplicates. */
class FreelanceSkillTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['freelance_skill_id', 'locale', 'name', 'normalized'];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }
}
