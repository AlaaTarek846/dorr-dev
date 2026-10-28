<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A dispute on one part of the work (FL-704), decided by the staff (FL-705) who move the held money (FL-507). */
class FreelanceDispute extends Model
{
    public const OPEN = 'open';

    public const DECIDED = 'decided';

    public const APPEALED = 'appealed';

    public const RESOLVED = 'resolved';

    public const REASONS = ['not_as_agreed', 'not_delivered', 'late', 'quality', 'out_of_scope', 'no_response', 'other'];

    public const DECISIONS = ['refund', 'release', 'split'];

    protected $fillable = [
        'uuid', 'project_id', 'agreement_id', 'milestone_id', 'opened_by', 'opener_id', 'reason', 'details', 'milestone_status', 'status',
        'respond_by', 'decision', 'freelancer_bp', 'decision_note', 'decided_by_admin_id', 'decided_at', 'executes_at', 'appealed_by',
        'appeal_reason', 'appealed_at', 'final_admin_id', 'resolved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceDispute $d): void {
            $d->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'respond_by' => 'datetime', 'decided_at' => 'datetime', 'executes_at' => 'datetime', 'appealed_at' => 'datetime', 'resolved_at' => 'datetime',
            'freelancer_bp' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(FreelanceDisputeEvidence::class, 'dispute_id');
    }
}
