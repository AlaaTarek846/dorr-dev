<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;

class AiArSceneItem extends Model
{
    protected $table = 'ai_ar_scene_items';

    protected $fillable = ['experience_version_id', 'asset_id', 'asset_version_id', 'transform', 'tint', 'visible', 'anchor_type', 'label', 'info'];

    protected function casts(): array
    {
        return ['transform' => 'array', 'info' => 'array', 'visible' => 'boolean'];
    }
}
