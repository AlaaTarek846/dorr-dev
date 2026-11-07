<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * FS-150b: one uploaded copy of a Workroom file - numbered 1, 2, 3 and never changed. The bytes are in the media library on the private
 * disk, exactly as uploaded (no conversion), only after the upload passed the delivery's size / type / scan checks.
 *
 * @property int $id
 * @property string $uuid
 * @property int $file_id
 * @property int $number
 * @property ?string $note
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $created_by_side
 * @property int $created_by_id
 * @property ?Carbon $created_at
 */
class FreelanceWorkroomFileVersion extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const COLLECTION = 'file';

    public const UPDATED_AT = null;

    protected $table = 'freelance_workroom_file_versions';

    protected $fillable = ['uuid', 'file_id', 'number', 'note', 'original_name', 'mime_type', 'size_bytes', 'created_by_side', 'created_by_id', 'created_at'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'size_bytes' => 'integer', 'created_by_id' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (FreelanceWorkroomFileVersion $v): void {
            $v->uuid ??= (string) Str::uuid();
        });
    }

    protected function webpSkipCollections(): array
    {
        return [self::COLLECTION];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(FreelanceWorkroomFile::class, 'file_id');
    }

    /** Keeps the upload as it is on the private disk. */
    public function addFile(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, self::COLLECTION, (string) $this->number)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection(self::COLLECTION, self::DISK);
    }

    /** The stored upload (a version has exactly one). */
    public function stored(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }
}
