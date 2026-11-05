<?php

namespace Modules\Moving\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * A customer's report after delivery (damage, a missing piece…) or a side's request for an exception (cancelling for
 * a reason that is the other side's fault or force majeure). A person decides it, never the system (REQ-006). Photos
 * are in the media collection `evidence`.
 */
class MovingCase extends Model implements HasMedia
{
    use HasMediaTrait;

    public const REPORT = 'report';

    public const EXCEPTION = 'exception';

    protected $table = 'moving_cases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['claim_minor' => 'integer', 'held_minor' => 'integer', 'award_minor' => 'integer', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MovingRequest::class, 'request_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
