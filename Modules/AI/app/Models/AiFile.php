<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiFile extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_PROJECT = 'project';

    public const SOURCE_CONVERSATION = 'conversation';

    public const SOURCE_EXTERNAL = 'external';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'source_type',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'processing_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function processingSteps(): HasMany
    {
        return $this->hasMany(AiFileProcessing::class, 'file_id');
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(AiKnowledgeSource::class, 'file_id');
    }
}
