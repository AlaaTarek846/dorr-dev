<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderHealth extends Model
{
    // Bug found 2026-09-24 while manually testing the chat end-to-end:
    // the migration creates 'ai_provider_health' (singular), but with no
    // $table override Eloquent's default pluralization queried
    // 'ai_provider_healths' - a table that has never existed - which
    // crashed every single chat message with a raw 500 the moment
    // AiCircuitBreaker actually started reading/writing this model for
    // real. Explicit $table is the fix; it must match the migration's
    // Schema::create() name exactly.
    protected $table = 'ai_provider_health';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNHEALTHY = 'unhealthy';

    public const STATUS_RECOVERING = 'recovering';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'check_type',
        'health_score',
        'status',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'health_score' => 'decimal:2',
            'details' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
