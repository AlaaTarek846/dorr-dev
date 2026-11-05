<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Provider\Enums\MembershipStatus;

/**
 * A person's seat in an establishment (§14): the owner, or someone invited by phone with a set of permissions and
 * branches (§17). One person may hold seats in several establishments and switches between them in the app.
 */
class Membership extends Model
{
    protected $table = 'provider_memberships';

    protected $fillable = [
        'uuid', 'establishment_id', 'provider_id', 'phone', 'is_owner', 'is_exclusive', 'all_branches',
        'offer_limit_minor', 'status', 'invited_by_id', 'joined_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'is_exclusive' => 'boolean',
            'all_branches' => 'boolean',
            'offer_limit_minor' => 'integer',
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'provider_membership_branches', 'membership_id', 'branch_id');
    }

    public function permissionRows(): HasMany
    {
        return $this->hasMany(MembershipPermission::class, 'membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
