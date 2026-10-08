<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10 (doc S3): the explicit many-to-many conversation<->file
 * relationship row. See the ai_conversation_files migration's own
 * docblock for why this exists alongside (not instead of)
 * ai_files.conversation_id. `status` is this RELATIONSHIP's own
 * lifecycle (attached/detached), never to be confused with the file's
 * own `AiFile::processing_status` - a file can be `attached` to a
 * conversation while its processing_status is still `processing` or
 * even `failed`; AiConversationFileScope is what decides searchability
 * from the combination of both.
 */
class AiConversationFile extends Model
{
    public const STATUS_ATTACHED = 'attached';

    public const STATUS_DETACHED = 'detached';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'file_id',
        'status',
        'attached_by_type',
        'attached_by_id',
        'detached_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detached_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'file_id');
    }
}
