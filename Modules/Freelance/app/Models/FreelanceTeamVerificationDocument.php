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
 * FS-202b: a paper of a team's verification request (D4) - one private file with a title. Kept on the private disk, never behind a link; only
 * the team's Owners and an admin with the right permission open it. `request_id` is null until the Owner sends it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property ?int $request_id
 * @property string $title
 */
class FreelanceTeamVerificationDocument extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const COLLECTION = 'file';

    protected $table = 'freelance_team_verification_documents';

    protected $fillable = ['uuid', 'team_id', 'request_id', 'uploaded_by_freelancer_id', 'title'];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceTeamVerificationDocument $d) => $d->uuid ??= (string) Str::uuid());
    }

    /** Kept as uploaded: a paper, not a picture to compress. */
    protected function webpSkipCollections(): array
    {
        return [self::COLLECTION];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id')->withTrashed();
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    public function setFile(UploadedFile $file): Media
    {
        $this->clearMediaCollection(self::COLLECTION);

        return $this->addUploadedMedia($file, self::COLLECTION)->toMediaCollection(self::COLLECTION, self::DISK);
    }
}
