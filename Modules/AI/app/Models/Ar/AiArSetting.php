<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;

class AiArSetting extends Model
{
    protected $table = 'ai_ar_settings';

    protected $fillable = ['is_enabled', 'max_asset_mb', 'max_triangles', 'capture_retention_days', 'commission_percent', 'escrow_release_days'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'max_asset_mb' => 'integer', 'max_triangles' => 'integer', 'capture_retention_days' => 'integer', 'commission_percent' => 'float', 'escrow_release_days' => 'integer'];
    }

    public static function current(): self
    {
        $setting = static::query()->firstOrCreate([], ['is_enabled' => true]);

        // A fresh row only holds the attributes passed in; reload to get the column defaults.
        return $setting->wasRecentlyCreated ? $setting->refresh() : $setting;
    }
}
