<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A 3D model (GLB). Library models are public once an admin approves their licence; uploads stay private to the owner. */
class AiArAsset extends Model
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const ARCHIVED = 'archived';

    public const LICENSES = ['own', 'cc0', 'cc_by', 'purchased', 'generated'];

    protected $table = 'ai_ar_assets';

    protected $fillable = ['slug', 'name_ar', 'name_en', 'category', 'source', 'license', 'license_note', 'attribution', 'owner_type', 'owner_id', 'status', 'current_version_id', 'is_public'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'is_public' => 'boolean', 'current_version_id' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiArAssetVersion::class, 'asset_id')->orderBy('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AiArAssetVersion::class, 'current_version_id');
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name_en) : $this->name_en;
    }
}
