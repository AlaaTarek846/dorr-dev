<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationInstruction extends Model
{
    public const SOURCE_USER = 'user';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_TEMPLATE = 'template';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'source_type',
        'instruction',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
