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
 * An identity document a freelancer uploaded (FL-107): `front` and maybe `back`, kept on the private disk — never behind a
 * public link; only its owner and an admin with the right permission open it.
 *
 * draft (uploaded, not sent yet) → pending (sent with a request) → approved / rejected; an approved one becomes `replaced`
 * when a newer one of the same type is approved, and `expired` after its date.
 */
class FreelanceVerificationDocument extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const SIDES = ['front', 'back'];

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const REPLACED = 'replaced';

    protected $fillable = ['uuid', 'freelancer_id', 'document_type_id', 'request_id', 'number', 'expires_on', 'status', 'rejection_reason', 'reviewed_by_admin_id', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceVerificationDocument $d) => $d->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'reviewed_at' => 'datetime', 'document_type_id' => 'integer', 'request_id' => 'integer'];
    }

    /** Kept as uploaded: these are identity documents, not pictures to compress. */
    protected function webpSkipCollections(): array
    {
        return self::SIDES;
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(FreelanceDocumentType::class, 'document_type_id')->withTrashed();
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

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }
}
