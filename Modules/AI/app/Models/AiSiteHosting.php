<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSiteHosting extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRACE = 'grace';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ai_site_hostings';

    protected $fillable = [
        'project_id', 'owner_type', 'owner_id', 'plan_id', 'subdomain', 'status', 'published_version_id', 'amount', 'currency',
        'period', 'country_id', 'auto_renew', 'admin_suspended', 'starts_at', 'ends_at', 'grace_ends_at', 'suspended_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'amount' => 'float',
            'auto_renew' => 'boolean',
            'admin_suspended' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiSiteProject::class, 'project_id')->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiSiteHostingPlan::class, 'plan_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AiSiteVersion::class, 'published_version_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AiSiteHostingPayment::class, 'hosting_id');
    }

    /** Served to the public: paid up, or inside the grace window after expiry. */
    public function isLive(): bool
    {
        if ($this->admin_suspended) {
            return false;
        }

        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_GRACE], true)
            || ($this->status === self::STATUS_CANCELLED && $this->ends_at !== null && $this->ends_at->isFuture());
    }
}
