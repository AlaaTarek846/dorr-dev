<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One part of an agreement, paid when the client approves it (FL-402). */
class FreelanceMilestone extends Model
{
    /** @deprecated the status is an enum now (FL-403) */
    public const UNFUNDED = 'unfunded';

    protected $fillable = [
        'uuid', 'agreement_id', 'project_id', 'position', 'title', 'description', 'amount_minor', 'duration_days',
        'client_fee_minor', 'commission_minor', 'net_minor', 'status', 'version', 'delivery_note', 'revision_reason', 'revisions_used',
        'funded_at', 'started_at', 'delivered_at', 'approved_at', 'released_at', 'paid_minor', 'review_reminded_at',
        'cancel_requested_by', 'cancel_requested_at', 'cancel_reason', 'refunded_minor',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceMilestone $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'client_fee_minor' => 'integer', 'commission_minor' => 'integer', 'net_minor' => 'integer', 'position' => 'integer', 'duration_days' => 'integer',
            'status' => \Modules\Freelance\Enums\FreelanceMilestoneStatus::class, 'version' => 'integer', 'revisions_used' => 'integer',
            'funded_at' => 'datetime', 'started_at' => 'datetime', 'delivered_at' => 'datetime', 'approved_at' => 'datetime',
            'released_at' => 'datetime', 'paid_minor' => 'integer', 'review_reminded_at' => 'datetime',
            'cancel_requested_at' => 'datetime', 'refunded_minor' => 'integer'];
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }
}
