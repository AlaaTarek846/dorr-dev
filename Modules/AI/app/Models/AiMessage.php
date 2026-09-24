<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
