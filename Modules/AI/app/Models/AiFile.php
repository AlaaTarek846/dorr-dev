<?php

namespace Modules\AI\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiFile extends Model
{
    use SoftDeletes;

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_PROJECT = 'project';

    public const SOURCE_CONVERSATION = 'conversation';

    public const SOURCE_EXTERNAL = 'external';

    /**
     * Acceptance criteria doc S2/S12/S27 lifecycle: uploaded -> validating
     * -> processing -> ready, or ... -> failed. `deleted` is recorded on
     * the row itself (in addition to the `deleted_at` soft-delete
     * timestamp) so a status filter alone is enough to tell the state
     * without also checking trashed().
     *
     * STATUS_PENDING/STATUS_REJECTED are kept, not removed, only because
     * earlier Phase 1 code/tests/admin-UI labels already used them - new
     * code should use STATUS_UPLOADED/STATUS_FAILED instead.
     */
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    /** @deprecated use STATUS_UPLOADED */
    public const STATUS_PENDING = 'pending';

    /** @deprecated use STATUS_FAILED */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'source_type',
        'conversation_id',
        'message_id',
        'file_name',
        'stored_name',
        'file_path',
        'disk',
        'mime_type',
        'extension',
        'file_size',
        'checksum',
        'processing_status',
        'processing_error',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    public function processingSteps(): HasMany
    {
        return $this->hasMany(AiFileProcessing::class, 'file_id');
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(AiKnowledgeSource::class, 'file_id');
    }

    /**
     * Phase 8: the file's chunk rows (any content_version, active or
     * not) - used by the admin File Engine dashboard to show chunking/
     * indexing status without a separate query per status value.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiFileChunk::class, 'file_id');
    }

    /**
     * Phase 10: every conversation this file is explicitly attached to
     * (any status) via the many-to-many ai_conversation_files table -
     * distinct from (and in addition to) $conversation_id above, which
     * only ever names the single conversation this file was first
     * uploaded into.
     */
    public function conversationFiles(): HasMany
    {
        return $this->hasMany(AiConversationFile::class, 'file_id');
    }

    /**
     * Acceptance criteria doc S3 ("support ownership checks") and S15/S23
     * (authorization, IDOR tests) - the single place that defines what
     * "this file belongs to this caller" means, so controllers/services
     * never re-implement the comparison themselves.
     */
    public function isOwnedBy(Authenticatable $owner): bool
    {
        return $this->owner_type === $owner->getMorphClass()
            && (string) $this->owner_id === (string) $owner->getAuthIdentifier();
    }
}
