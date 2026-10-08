<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCodeExecution extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'conversation_id',
        'language',
        'driver',
        'code',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'duration_ms',
        'attempt_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'attempt_number' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->exit_code === 0;
    }
}
