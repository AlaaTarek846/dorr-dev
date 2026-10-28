<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;

class AiArDeviceProfile extends Model
{
    public const TIER_FULL = 'full';

    public const TIER_BASIC = 'basic';

    public const TIER_FALLBACK = 'fallback_only';

    protected $table = 'ai_ar_device_profiles';

    protected $fillable = ['platform', 'manufacturer', 'model_pattern', 'arcore_supported', 'depth_supported', 'tier', 'notes', 'verified_at'];

    protected function casts(): array
    {
        return ['arcore_supported' => 'boolean', 'depth_supported' => 'boolean', 'verified_at' => 'datetime'];
    }
}
