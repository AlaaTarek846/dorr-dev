<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A campaign draft and its way to a platform (U09). Spec 05: DRAFT → VALIDATED → AWAITING_APPROVAL → APPROVED →
 * SUBMITTING → PUBLISHED / REJECTED / FAILED / UNKNOWN — never DRAFT → PUBLISHED. UNKNOWN blocks any new publish
 * until a reconcile finds out what the platform did (AT-06). Campaigns are created PAUSED on the platform: the
 * person starts the spend in the platform's own tools.
 */
class MarketingCampaign extends Model
{
    use SoftDeletes;

    public const OBJECTIVES = ['awareness', 'traffic', 'engagement', 'leads', 'sales'];

    public const DRAFT = 'DRAFT';

    public const VALIDATED = 'VALIDATED';

    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const SUBMITTING = 'SUBMITTING';

    public const PUBLISHED = 'PUBLISHED';

    public const REJECTED = 'REJECTED';

    public const FAILED = 'FAILED';

    public const UNKNOWN = 'UNKNOWN';

    protected $fillable = [
        'uuid', 'project_id', 'integration_id', 'content_item_id', 'provider', 'name', 'objective', 'status', 'daily_budget_minor',
        'total_budget_minor', 'currency', 'start_at', 'end_at', 'targeting', 'version', 'payload_hash', 'validation', 'platform_ref',
        'platform_status', 'last_error', 'published_at', 'metrics_synced_at', 'created_by_type', 'created_by_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $campaign) {
            $campaign->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'targeting' => 'array',
            'validation' => 'array',
            'daily_budget_minor' => 'integer',
            'total_budget_minor' => 'integer',
            'version' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'published_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(MarketingIntegration::class, 'integration_id');
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(MarketingContentItem::class, 'content_item_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MarketingCampaignAction::class, 'campaign_id');
    }

    /** What an approval and a publish are bound to (POL-01): everything that changes what is created or spent. */
    public function payload(): array
    {
        return [
            'provider' => $this->provider,
            'integration' => $this->integration_id,
            'name' => $this->name,
            'objective' => $this->objective,
            'daily_budget_minor' => $this->daily_budget_minor,
            'total_budget_minor' => $this->total_budget_minor,
            'currency' => $this->currency,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'targeting' => $this->targeting,
            'content' => $this->content_item_id,
        ];
    }
}
