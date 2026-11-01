<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The verified badge a freelancer earned in a skill by passing its test (FL-L08). Valid until `expires_at` (empty = no end) unless the admin revoked it. */
class FreelancerSkillBadge extends Model
{
    protected $fillable = ['freelancer_id', 'freelance_skill_id', 'attempt_id', 'score_percent', 'earned_at', 'expires_at', 'revoked_at', 'revoked_reason'];

    protected function casts(): array
    {
        return ['score_percent' => 'integer', 'earned_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
