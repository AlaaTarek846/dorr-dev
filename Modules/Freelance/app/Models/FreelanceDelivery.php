<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One delivery of a part (FL-601): a numbered version with its note and files, on the private disk. The files are kept as they were
 * uploaded (no conversion) and a version is never changed — a new delivery is a new version.
 *
 * @property int $id
 * @property string $uuid
 * @property int $milestone_id
 * @property int $number
 * @property ?string $note
 * @property ?string $revision_reason
 */
class FreelanceDelivery extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'milestone_id', 'number', 'note', 'revision_reason', 'revision_requested_at', 'created_at'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceDelivery $d): void {
            $d->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['revision_requested_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected function webpSkipCollections(): array
    {
        return ['files'];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    public function addFile(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'files', (string) (($this->getMedia('files')->max('order_column') ?? 0) + 1))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection('files', self::DISK);
    }
}
