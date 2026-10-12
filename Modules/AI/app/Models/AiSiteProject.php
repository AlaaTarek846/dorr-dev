<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiSiteProject extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const ACCESS_PLAN = 'plan';

    public const ACCESS_PURCHASE = 'purchase';

    protected $table = 'ai_site_projects';

    protected $fillable = [
        'owner_type', 'owner_id', 'title', 'slug', 'status', 'access_type', 'purchase_id', 'brief',
        'current_version_id', 'last_error', 'disabled_at',
    ];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'brief' => 'array', 'disabled_at' => 'datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(AiSitePurchase::class, 'purchase_id');
    }

    /** Edit packs bought for this site (ai_site_purchases rows that point at it). */
    public function editPacks(): HasMany
    {
        return $this->hasMany(AiSitePurchase::class, 'project_id')
            ->whereHas('offer', fn ($query) => $query->where('kind', AiSiteOffer::KIND_EDIT_PACK));
    }

    /** Purchases that unlocked the ZIP download of this site. */
    public function sourceDownloadPurchases(): HasMany
    {
        return $this->hasMany(AiSitePurchase::class, 'project_id')
            ->whereHas('offer', fn ($query) => $query->where('kind', AiSiteOffer::KIND_SOURCE_DOWNLOAD));
    }

    /** Builds/edits still unused in the edit packs bought for this site. */
    public function packGenerationsLeft(): int
    {
        return (int) $this->editPacks()->where('status', 'active')
            ->selectRaw('COALESCE(SUM(generations_included - generations_used), 0) as left_total')
            ->value('left_total');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiSiteVersion::class, 'project_id')->orderByDesc('number');
    }

    public function hosting(): HasOne
    {
        return $this->hasOne(AiSiteHosting::class, 'project_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AiSiteVersion::class, 'current_version_id');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Storage folder holding the logo/images the customer uploaded. */
    public function assetsPath(): string
    {
        return 'ai-sites/'.$this->id.'/assets';
    }
}
