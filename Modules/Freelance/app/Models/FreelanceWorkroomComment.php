<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FS-150b: a note in the Workroom - on a file (optionally on one of its versions) or on a delivery of a part, never on both. Both sides
 * read it; the side that wrote it edits and deletes it. A deleted note is hidden but kept (soft delete).
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $agreement_id
 * @property ?int $file_id
 * @property ?int $version_number
 * @property ?int $delivery_id
 * @property string $body
 * @property string $author_side
 * @property int $author_id
 * @property ?Carbon $edited_at
 */
class FreelanceWorkroomComment extends Model
{
    use SoftDeletes;

    protected $table = 'freelance_workroom_comments';

    protected $fillable = ['uuid', 'project_id', 'agreement_id', 'file_id', 'version_number', 'delivery_id', 'body', 'scope', 'author_side', 'author_id', 'edited_at'];

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'author_id' => 'integer', 'edited_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceWorkroomComment $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(FreelanceWorkroomFile::class, 'file_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(FreelanceDelivery::class, 'delivery_id');
    }
}
