<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AI\Models\AiFileCitation;

class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'sequence_number',
        'request_id',
        'role',
        'content',
        'provider_key',
        'model',
        'tokens_used',
        'is_error',
        'generated_file',
        'confidence_score',
        'verification_warnings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'tokens_used' => 'integer',
            'is_error' => 'boolean',
            'generated_file' => 'array',
            'confidence_score' => 'decimal:3',
            'verification_warnings' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AiConversationAttachment::class, 'message_id');
    }

    /**
     * Phase 11 (doc S14/S44): citations UI needs a way to read the
     * Phase 9/10 file citations tied to this message's turn. There is
     * no FK from ai_requests to ai_messages (the relationship runs the
     * other way - AiMessage.request_id -> AiRequest.id), so this is a
     * direct hasMany matched on the shared request_id value rather than
     * a hasManyThrough: both ai_messages and ai_file_citations carry
     * the same request_id for one turn, so matching on that column
     * directly is correct and avoids a needless join through
     * ai_requests.
     */
    public function fileCitations(): HasMany
    {
        return $this->hasMany(AiFileCitation::class, 'request_id', 'request_id');
    }
}
