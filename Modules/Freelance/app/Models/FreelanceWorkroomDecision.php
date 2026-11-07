<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-150c: one decision of an agreement's Workroom. Proposed by a side, agreed or declined by the other; an agreed one is never edited - a new
 * decision that supersedes it replaces it. Withdrawing (delete) is only possible while it is still proposed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $agreement_id
 * @property ?int $milestone_id
 * @property string $title
 * @property string $body
 * @property string $status
 * @property string $proposed_by_side
 * @property int $proposed_by_id
 * @property ?Carbon $client_ack_at
 * @property ?Carbon $freelancer_ack_at
 * @property ?Carbon $answered_at
 * @property ?string $answer_note
 * @property ?int $supersedes_id
 */
class FreelanceWorkroomDecision extends Model
{
    use SoftDeletes;

    public const PROPOSED = 'proposed';

    public const AGREED = 'agreed';

    public const DECLINED = 'declined';

    public const SUPERSEDED = 'superseded';

    public const STATUSES = [self::PROPOSED, self::AGREED, self::DECLINED, self::SUPERSEDED];

    protected $table = 'freelance_workroom_decisions';

    protected $fillable = [
        'uuid', 'project_id', 'agreement_id', 'milestone_id', 'title', 'body', 'status', 'proposed_by_side', 'proposed_by_id',
        'client_ack_at', 'freelancer_ack_at', 'answered_at', 'answer_note', 'supersedes_id',
    ];

    protected function casts(): array
    {
        return ['client_ack_at' => 'datetime', 'freelancer_ack_at' => 'datetime', 'answered_at' => 'datetime', 'proposed_by_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceWorkroomDecision $d): void {
            $d->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    /** The agreed decision this one replaces. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** The agreed decision that replaced this one. */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id')->where('status', self::AGREED);
    }
}
