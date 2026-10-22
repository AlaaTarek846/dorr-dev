<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One generation attempt of a scene. */
class AiWorldSceneVersion extends Model
{
    protected $table = 'ai_world_scene_versions';

    protected $fillable = ['scene_id', 'number', 'media_generation_id', 'status', 'video_path', 'identity_score', 'error_code'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'media_generation_id' => 'integer', 'identity_score' => 'float'];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(AiWorldScene::class, 'scene_id');
    }
}
