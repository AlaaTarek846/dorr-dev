<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFailover extends Model
{
    public const TRIGGER_TIMEOUT = 'timeout';

    public const TRIGGER_PROVIDER_ERROR = 'provider_error';

    public const TRIGGER_UNAVAILABLE = 'unavailable';

    public const TRIGGER_HEALTH_THRESHOLD = 'health_threshold';

    public const TRIGGER_RATE_LIMIT = 'rate_limit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'primary_provider_id',
        'fallback_provider_id',
        'request_id',
        'trigger_type',
        'attempt_number',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
        ];
    }

    public function primaryProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'primary_provider_id');
    }

    public function fallbackProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'fallback_provider_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
