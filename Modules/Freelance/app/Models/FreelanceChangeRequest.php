<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A change to an active agreement, asked by one side and answered by the other (FL-404 / FL-405). */
class FreelanceChangeRequest extends Model
{
    public const TYPES = ['extend_days', 'scope', 'milestone_price', 'add_milestone'];

    public const PENDING = 'pending';

    protected $fillable = [
        'uuid', 'agreement_id', 'project_id', 'milestone_id', 'type', 'requested_by', 'requester_id', 'payload', 'reason', 'status', 'respond_by', 'answered_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceChangeRequest $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'respond_by' => 'datetime', 'answered_at' => 'datetime'];
    }

    /** Waiting for an answer, and still in time. */
    public function open(): bool
    {
        return $this->status === self::PENDING && ($this->respond_by === null || $this->respond_by->isFuture());
    }

    /** What the apps show: a request nobody answered in time reads as expired. */
    public function shownStatus(): string
    {
        return $this->status === self::PENDING && ! $this->open() ? 'expired' : $this->status;
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }
}
