<?php

namespace Modules\Chat\Models\Verification;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A document uploaded with a verification request (AV-011): `front` and maybe `back`, on the private
 * disk — never behind a public link; only the account and staff allowed to see requests open it.
 *
 * pending → accepted / rejected by a reviewer; `replaced` once the account uploads a newer one of the
 * same type (a completion).
 */
class ChatVerificationRequestDocument extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const SIDES = ['front', 'back'];

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const REPLACED = 'replaced';

    protected $fillable = ['request_id', 'document_type_id', 'status', 'review_note', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'document_type_id' => 'integer', 'reviewed_by' => 'integer', 'reviewed_at' => 'datetime'];
    }

    /** Kept as uploaded: identity documents, not pictures to compress. */
    protected function webpSkipCollections(): array
    {
        return self::SIDES;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ChatVerificationRequest::class, 'request_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ChatVerificationDocumentType::class, 'document_type_id')->withTrashed();
    }

    public function file(string $side): ?Media
    {
        return $this->getFirstMedia($side);
    }

    public function setFile(string $side, UploadedFile $file): Media
    {
        $this->clearMediaCollection($side);

        return $this->addUploadedMedia($file, $side)->toMediaCollection($side, self::DISK);
    }
}
