<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One generated clip of a project. */
class AiWorldScene extends Model
{
    public const DRAFT = 'draft';

    public const QUEUED = 'queued';

    public const GENERATING = 'generating';

    public const REVIEW = 'review';

    public const APPROVED = 'approved';

    public const FAILED = 'failed';

    protected $table = 'ai_world_scenes';

    protected $fillable = [
        'project_id', 'position', 'location_id', 'prompt', 'seconds', 'status', 'attempts', 'price_minor',
        'refunded_minor', 'current_version_id', 'error_code',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'seconds' => 'integer', 'attempts' => 'integer', 'price_minor' => 'integer', 'refunded_minor' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiWorldProject::class, 'project_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiWorldSceneVersion::class, 'scene_id')->orderBy('number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AiWorldSceneVersion::class, 'current_version_id');
    }
}
