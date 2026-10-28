<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** One row: whether DORR AI WORLD is on, and the limits every project obeys. */
class AiWorldSetting extends Model
{
    protected $table = 'ai_world_settings';

    protected $fillable = ['is_enabled', 'max_scenes_per_project', 'max_retries_per_scene', 'identity_threshold', 'retention_days', 'default_provider_id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'max_scenes_per_project' => 'integer',
            'max_retries_per_scene' => 'integer',
            'identity_threshold' => 'float',
            'retention_days' => 'integer',
            'default_provider_id' => 'integer',
        ];
    }

    public static function current(): self
    {
        $setting = static::query()->firstOrCreate([], ['is_enabled' => true]);

        // A freshly created row only holds the attributes passed in; reload to get the column defaults.
        return $setting->wasRecentlyCreated ? $setting->refresh() : $setting;
    }
}
