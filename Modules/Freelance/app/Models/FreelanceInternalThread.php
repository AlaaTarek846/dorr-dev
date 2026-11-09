<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-150d: one thread of a team's internal chat - the team's general one (`project_id` null) or the one about a project. The client never
 * sees it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property ?int $project_id
 * @property ?int $created_by_freelancer_id
 * @property ?Carbon $last_message_at
 * @property ?string $last_message_preview
 */
class FreelanceInternalThread extends Model
{
    protected $table = 'freelance_internal_threads';

    protected $fillable = ['uuid', 'team_id', 'project_id', 'created_by_freelancer_id', 'last_message_at', 'last_message_preview'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceInternalThread $t): void {
            $t->uuid ??= (string) Str::uuid();
        });
    }

    public function isGeneral(): bool
    {
        return $this->project_id === null;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FreelanceInternalMessage::class, 'thread_id');
    }
}
