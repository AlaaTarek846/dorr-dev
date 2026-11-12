<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A net amount a freelancer earned from a part of the work (FL-505 / FL-509) or from a consultation (FL-L07). */
class FreelanceEarning extends Model
{
    public const PENDING = 'pending';

    public const RELEASED = 'released';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'freelancer_id', 'project_id', 'milestone_id', 'consultation_id', 'country_id', 'kind', 'amount_minor', 'currency_code', 'status', 'release_at',
        'released_at', 'wallet_transaction_id', 'key',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'release_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultation::class, 'consultation_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    /** What the earning came from, in words: the project's title or the consultation's. */
    public function sourceTitle(): string
    {
        return $this->consultation_id !== null ? (string) $this->consultation?->label() : (string) $this->project?->title;
    }
}
