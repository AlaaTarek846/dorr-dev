<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * FS-201: a freelancers' team. It works in front of the client as one party and its Owner signs for it (D2). Who is in it, and with which
 * role, is `members`; a person who left or was removed keeps a row (never deleted) so the history shows who was there.
 *
 * @property int $id
 * @property string $uuid
 * @property int $country_id
 * @property string $name
 * @property string $status
 * @property ?string $status_reason
 * @property ?string $bio
 * @property string $visibility
 * @property bool $accepting_projects
 * @property ?Carbon $verified_at
 * @property ?int $created_by_freelancer_id
 * @property ?Carbon $deleted_at
 */
class FreelanceTeam extends Model implements HasMedia
{
    use HasMediaTrait, SoftDeletes;

    // FS-202a: who may open the profile - anyone (`public`), or only the team and the clients it made an offer to (`private`).
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    public const VISIBILITIES = [self::PUBLIC, self::PRIVATE];

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::ACTIVE, self::SUSPENDED, self::ARCHIVED];

    protected $table = 'freelance_teams';

    protected $fillable = ['uuid', 'country_id', 'name', 'bio', 'visibility', 'accepting_projects', 'verified_at', 'status', 'status_reason', 'status_changed_at', 'created_by_freelancer_id'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceTeam $team): void {
            $team->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['status_changed_at' => 'datetime', 'accepting_projects' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'created_by_freelancer_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(FreelanceTeamMember::class, 'team_id');
    }

    /** The people in the team now. */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', FreelanceTeamMember::ACTIVE);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FreelanceTeamInvitation::class, 'team_id');
    }

    /** FS-202b: the tries to get the team verified, newest last. */
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(FreelanceTeamVerificationRequest::class, 'team_id');
    }

    /** FS-202a: the team's own works (a person's portfolio never lists them). */
    public function portfolioItems(): HasMany
    {
        return $this->hasMany(FreelancerPortfolioItem::class, 'team_id');
    }

    /** The logo, a public image like a freelancer's avatar (null = none). */
    public function logoUrl(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }
}
