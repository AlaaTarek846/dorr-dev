<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FS-201: one freelancer in one team, with a role. One row per person and team (unique): leaving sets `status` to `left`, being removed sets
 * `removed`, and coming back turns the same row `active` again. The row is never deleted.
 *
 * @property int $id
 * @property int $team_id
 * @property int $freelancer_id
 * @property string $role
 * @property string $status
 * @property ?array $permissions
 * @property ?string $job_title FS-213: what he does in the team
 * @property bool $show_in_profile
 * @property ?Carbon $joined_at
 * @property ?Carbon $left_at
 * @property ?string $left_reason
 * @property ?int $removed_by_freelancer_id
 */
class FreelanceTeamMember extends Model
{
    public const OWNER = 'owner';

    public const PM = 'pm';

    public const MEMBER = 'member';

    public const ROLES = [self::OWNER, self::PM, self::MEMBER];

    public const ACTIVE = 'active';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    public const STATUSES = [self::ACTIVE, self::LEFT, self::REMOVED];

    protected $table = 'freelance_team_members';

    protected $fillable = ['team_id', 'freelancer_id', 'role', 'job_title', 'status', 'permissions', 'show_in_profile', 'joined_at', 'left_at', 'left_reason', 'removed_by_freelancer_id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'show_in_profile' => 'boolean', 'joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isOwner(): bool
    {
        return $this->role === self::OWNER && $this->isActive();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
