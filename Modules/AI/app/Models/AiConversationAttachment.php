<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'message_id',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'ai_file_id',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    /**
     * The AiFileEngine-processed counterpart of this attachment - see the
     * ai_file_id migration's docblock. Nullable: it is only set once
     * AiFileEngine::process() has run for this upload.
     */
    public function aiFile(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'ai_file_id');
    }
}
