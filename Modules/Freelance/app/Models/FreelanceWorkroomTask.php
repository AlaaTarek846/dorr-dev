<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-150a: one task of an agreement's Workroom, the responsibility of one side (docs/modules/freelance/TEAMS-ERD.md).
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $agreement_id
 * @property ?int $milestone_id
 * @property string $title
 * @property ?string $description
 * @property string $status
 * @property ?Carbon $due_on
 * @property string $scope
 * @property string $assignee_side
 * @property ?int $assignee_freelancer_id
 * @property string $created_by_side
 * @property int $created_by_id
 * @property ?Carbon $completed_at
 */
class FreelanceWorkroomTask extends Model
{
    use SoftDeletes;

    public const TODO = 'todo';

    public const DOING = 'doing';

    public const DONE = 'done';

    public const BLOCKED = 'blocked';

    public const STATUSES = [self::TODO, self::DOING, self::DONE, self::BLOCKED];

    public const CLIENT = 'client';

    public const FREELANCER = 'freelancer';

    public const SIDES = [self::CLIENT, self::FREELANCER];

    public const SHARED = 'shared';

    /** FS-207: the freelancers team's own item - the client never sees it. */
    public const TEAM = 'team';

    protected $table = 'freelance_workroom_tasks';

    protected $fillable = [
        'uuid', 'project_id', 'agreement_id', 'milestone_id', 'title', 'description', 'status', 'due_on', 'scope',
        'assignee_side', 'assignee_freelancer_id', 'created_by_side', 'created_by_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_at' => 'datetime', 'created_by_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceWorkroomTask $t): void {
            $t->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }

    /** FS-206b: the person of the freelancers team the task is given to (a team project only). */
    public function assigneeFreelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'assignee_freelancer_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }
}
