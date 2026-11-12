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

    /** error_code written on a failed / blocked request (2026-11-11). */
    const ERROR_PROVIDER = 'provider_error';

    const ERROR_CIRCUIT_OPEN = 'circuit_open';

    const ERROR_SAFETY_BLOCKED = 'safety_blocked';

    /** A safety rule asked the person to confirm first (status cancelled; sending it again confirms). */
    const ERROR_SAFETY_CONFIRMATION = 'safety_confirmation_required';

    const ERROR_IMAGE_QUOTA = 'image_quota';

    const ERROR_FAILED = 'request_failed';

    /**
     * The error_code for a failed provider call from its failure message
     * (image quota refusals carry an "image_quota:<reason>" message; a call
     * skipped by an open circuit breaker the ai.circuit_breaker_open text).
     */
    public static function failureCode(?string $message): string
    {
        $message = (string) $message;

        return match (true) {
            str_starts_with($message, 'image_quota:') => self::ERROR_IMAGE_QUOTA,
            $message !== '' && $message === __('ai.circuit_breaker_open') => self::ERROR_CIRCUIT_OPEN,
            default => self::ERROR_PROVIDER,
        };
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'gateway_id',
        'intent_id',
        'source',
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
        // withTrashed: the log still names a gateway moved to the trash.
        return $this->belongsTo(AiGateway::class, 'gateway_id')->withTrashed();
    }

    public function intent(): BelongsTo
    {
        // withTrashed: history still names an intent moved to the trash.
        return $this->belongsTo(AiIntent::class, 'intent_id')->withTrashed();
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
