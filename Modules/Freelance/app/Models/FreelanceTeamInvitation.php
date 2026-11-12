<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-201: an invitation to join a team, to one freelancer or by link (`freelancer_id` null). Answering it and turning it into a membership is
 * `TeamInvitations` (FS-203): a link invitation keeps its token and records who used it in `freelancer_id`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property ?int $freelancer_id
 * @property ?string $token
 * @property string $role
 * @property ?int $invited_by_freelancer_id
 * @property string $status
 * @property ?Carbon $expires_at
 * @property ?Carbon $responded_at
 */
class FreelanceTeamInvitation extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::ACCEPTED, self::DECLINED, self::EXPIRED, self::CANCELLED];

    protected $table = 'freelance_team_invitations';

    protected $fillable = ['uuid', 'team_id', 'freelancer_id', 'token', 'role', 'invited_by_freelancer_id', 'status', 'expires_at', 'responded_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceTeamInvitation $i): void {
            $i->uuid ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'invited_by_freelancer_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
