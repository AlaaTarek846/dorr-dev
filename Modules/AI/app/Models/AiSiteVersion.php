<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSiteVersion extends Model
{
    public const KIND_GENERATE = 'generate';

    public const KIND_EDIT = 'edit';

    public const KIND_RESTORE = 'restore';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_site_versions';

    protected $fillable = [
        'project_id', 'number', 'kind', 'status', 'instruction', 'files', 'total_bytes', 'provider_id',
        'model_key', 'counted', 'error_message', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['number' => 'integer', 'files' => 'array', 'total_bytes' => 'integer', 'counted' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiSiteProject::class, 'project_id');
    }

    /** Folder of this version's files on the private disk. */
    public function path(): string
    {
        return 'ai-sites/'.$this->project_id.'/v'.$this->number;
    }
}
