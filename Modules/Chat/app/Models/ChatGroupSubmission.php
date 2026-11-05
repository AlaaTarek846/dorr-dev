<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupScope;
use Spatie\MediaLibrary\HasMedia;

/**
 * A student's (or a team's) hand-in (GR-014). Its files: only the students in it and whoever marks.
 */
class ChatGroupSubmission extends Model implements HasMedia, ProtectedMediaOwner
{
    use HasMediaTrait;

    public const FILES = 'files';

    protected $fillable = ['assignment_id', 'participant_id', 'team', 'body', 'version', 'is_late', 'submitted_at'];

    protected function casts(): array
    {
        return ['team' => 'array', 'is_late' => 'boolean', 'submitted_at' => 'datetime', 'version' => 'integer'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ChatGroupAssignment::class, 'assignment_id');
    }

    /** @return list<int> the students in it */
    public function studentIds(): array
    {
        return array_values(array_unique(array_map('intval', [$this->participant_id, ...(array) ($this->team ?? [])])));
    }

    public function protectedMediaAllows(Model $viewer): bool
    {
        $assignment = $this->assignment;
        $member = $assignment ? GroupScope::member($viewer, $assignment->conversation_id) : null;
        if ($member === null) {
            return false;
        }

        return in_array($member->id, $this->studentIds(), true)
            || in_array('grading', GroupPermissions::ofParticipant($member, $assignment->conversation), true);
    }
}
