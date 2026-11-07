<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * FS-104: a person the owner of a project brought into it, with one role (docs/modules/freelance/PERMISSIONS.md).
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $user_id
 * @property ?int $invited_by
 * @property string $role
 * @property string $status
 * @property ?int $max_amount_minor
 * @property ?Carbon $ends_at
 * @property ?Carbon $invited_at
 * @property ?Carbon $responded_at
 * @property ?Carbon $ended_at
 */
class FreelanceProjectMember extends Model
{
    public const ROLES = ['manager', 'reviewer', 'collaborator', 'finance', 'delegate'];

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    protected $table = 'freelance_project_members';

    protected $fillable = ['uuid', 'project_id', 'user_id', 'invited_by', 'role', 'status', 'max_amount_minor', 'ends_at', 'invited_at', 'responded_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'max_amount_minor' => 'integer', 'ends_at' => 'datetime', 'invited_at' => 'datetime',
            'responded_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceProjectMember $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** A delegate whose time is up keeps reading but approves nothing. */
    public function delegationOver(): bool
    {
        return $this->role === 'delegate' && $this->ends_at !== null && $this->ends_at->isPast();
    }
}
