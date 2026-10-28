<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A trip video: one or more scenes charged together and stitched into one file. */
class AiWorldProject extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const GENERATING = 'generating';

    public const REVIEW = 'review';

    public const STITCHING = 'stitching';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $table = 'ai_world_projects';

    protected $fillable = [
        'owner_type', 'owner_id', 'country_id', 'identity_profile_id', 'title', 'mode', 'status', 'quality', 'brief', 'reference_files',
        'total_seconds', 'estimated_minor', 'charged_minor', 'refunded_minor', 'currency', 'charge_operation_id',
        'final_video_path', 'error_code', 'confirmed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'country_id' => 'integer',
            'reference_files' => 'array',
            'total_seconds' => 'integer',
            'estimated_minor' => 'integer',
            'charged_minor' => 'integer',
            'refunded_minor' => 'integer',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(AiWorldScene::class, 'project_id')->orderBy('position');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(AiWorldCostLedger::class, 'project_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
