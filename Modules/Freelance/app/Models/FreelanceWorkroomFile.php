<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * FS-150b: one file of an agreement's Workroom. It is a title (optionally tied to a part) with numbered versions; what was uploaded lives
 * in the versions. Deleting it only hides it (soft delete) - the versions stay on the private disk for the record.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $agreement_id
 * @property ?int $milestone_id
 * @property string $title
 * @property ?string $description
 * @property int $versions_count
 * @property string $created_by_side
 * @property int $created_by_id
 */
class FreelanceWorkroomFile extends Model
{
    use SoftDeletes;

    protected $table = 'freelance_workroom_files';

    protected $fillable = ['uuid', 'project_id', 'agreement_id', 'milestone_id', 'title', 'description', 'versions_count', 'scope', 'created_by_side', 'created_by_id'];

    protected function casts(): array
    {
        return ['versions_count' => 'integer', 'created_by_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceWorkroomFile $f): void {
            $f->uuid ??= (string) Str::uuid();
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

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    /** @return HasMany<FreelanceWorkroomFileVersion, $this> newest first */
    public function versions(): HasMany
    {
        return $this->hasMany(FreelanceWorkroomFileVersion::class, 'file_id')->orderByDesc('number');
    }

    /** The newest version - what the list shows and what a download without a number means. */
    public function current(): HasOne
    {
        return $this->hasOne(FreelanceWorkroomFileVersion::class, 'file_id')->ofMany('number', 'max');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FreelanceWorkroomComment::class, 'file_id');
    }
}
