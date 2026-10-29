<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** The agreement on a project after the client chose an offer (FL-401). Frozen once both sides approved it. */
class FreelanceAgreement extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const ACTIVE = 'active';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Every part approved or cancelled (FL-408). */
    public const COMPLETED = 'completed';

    protected $fillable = [
        'uuid', 'project_id', 'offer_id', 'client_id', 'freelancer_id', 'scope', 'includes', 'excludes', 'price_minor', 'currency_code',
        'duration_days', 'policy', 'money', 'status', 'version', 'sent_at', 'client_approved_at', 'freelancer_approved_at', 'activated_at',
        'completed_at', 'expires_at', 'end_reason', 'report_every_days', 'next_report_due_at', 'last_report_at', 'report_reminded_at', 'report_late_notified_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceAgreement $a): void {
            $a->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'money' => 'array',
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'version' => 'integer',
            'sent_at' => 'datetime',
            'client_approved_at' => 'datetime',
            'freelancer_approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'report_every_days' => 'integer',
            'next_report_due_at' => 'datetime',
            'last_report_at' => 'datetime',
            'report_reminded_at' => 'datetime',
            'report_late_notified_at' => 'datetime',
        ];
    }

    /** Still being agreed on (it can change, or end without starting). */
    public function open(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function milestones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FreelanceMilestone::class, 'agreement_id');
    }

    public function disputes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FreelanceDispute::class, 'agreement_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FreelanceOffer::class, 'offer_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
