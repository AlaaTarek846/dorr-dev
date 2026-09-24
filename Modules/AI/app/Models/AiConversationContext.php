<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationContext extends Model
{
    public const TYPE_MESSAGE_HISTORY = 'message_history';

    public const TYPE_SUMMARY = 'summary';

    public const TYPE_SYSTEM_INSTRUCTION = 'system_instruction';

    public const TYPE_ATTACHMENT = 'attachment';

    public const TYPE_MEMORY = 'memory';

    public const TYPE_EXTERNAL = 'external';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'context_type',
        'content',
        'included',
        'token_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'included' => 'boolean',
            'token_count' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
