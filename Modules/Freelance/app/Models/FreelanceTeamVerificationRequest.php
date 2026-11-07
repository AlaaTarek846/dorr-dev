<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-202b: one try to get a team verified (D4): the papers sent together, waiting for an admin (`submitted`), then `approved` or `rejected` with a reason.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $status
 * @property ?string $reason
 * @property ?Carbon $submitted_at
 * @property ?Carbon $decided_at
 */
class FreelanceTeamVerificationRequest extends Model
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'freelance_team_verification_requests';

    protected $fillable = ['uuid', 'team_id', 'submitted_by_freelancer_id', 'status', 'submitted_at', 'decided_at', 'decided_by_admin_id', 'reason'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (FreelanceTeamVerificationRequest $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id')->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FreelanceTeamVerificationDocument::class, 'request_id');
    }
}
