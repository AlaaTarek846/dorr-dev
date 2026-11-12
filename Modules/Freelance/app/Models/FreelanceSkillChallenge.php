<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * FS-602: a freelancer's practical challenge for the advanced or expert level of a skill — what they built (a link) and a word on it — and
 * what staff decided.
 *
 * @property int $id
 * @property string $uuid
 * @property int $freelancer_id
 * @property int $freelance_skill_id
 * @property string $target_level
 * @property ?string $link
 * @property string $note
 * @property string $status
 * @property ?string $granted_level
 * @property ?string $decision_note
 * @property ?int $decided_by_admin_id
 * @property ?\Illuminate\Support\Carbon $decided_at
 */
class FreelanceSkillChallenge extends Model
{
    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::SUBMITTED, self::ACCEPTED, self::REJECTED, self::WITHDRAWN];

    /** The levels a challenge is for. */
    public const TARGETS = [FreelancerSkillBadge::ADVANCED, FreelancerSkillBadge::EXPERT];

    protected $fillable = ['uuid', 'freelancer_id', 'freelance_skill_id', 'target_level', 'link', 'note', 'status', 'granted_level', 'decision_note', 'decided_by_admin_id', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }
}
