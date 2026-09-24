<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiRequest extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_BLOCKED = 'blocked';

    const STATUS_RETRYING = 'retrying';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'gateway_id',
        'intent_id',
        'provider_id',
        'model_key',
        'prompt',
        'correlation_id',
        'idempotency_key',
        'retry_count',
        'error_code',
        'error_message',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(AiGateway::class, 'gateway_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(AiIntent::class, 'intent_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(AiResponse::class, 'request_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(AiRequestCitation::class, 'request_id');
    }

    public function usage(): HasOne
    {
        return $this->hasOne(AiUsage::class, 'request_id');
    }
}
