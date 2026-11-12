<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;

/**
 * One instruction of a conversation: a system message the chat adds to every new message of that conversation, the
 * highest priority first (AiChatService). Has the countries list (status, trash) but no translation table: the
 * instruction is a prompt sent to the AI as it is.
 *
 * Switched off or in the trash = never sent. `$instruction->is_active` reads `status` (HasIsActiveAlias).
 */
class AiConversationInstruction extends Model
{
    use HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    public const SOURCE_USER = 'user';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_TEMPLATE = 'template';

    public const SOURCES = [self::SOURCE_USER, self::SOURCE_SYSTEM, self::SOURCE_TEMPLATE];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'source_type',
        'instruction',
        'priority',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
