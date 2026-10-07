<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function versions(): HasMany
    {
        return $this->hasMany(AiSiteVersion::class, 'project_id')->orderByDesc('number');
    }

    public function hosting(): \Illuminate\Database\Eloquent\Relations\HasOne
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
