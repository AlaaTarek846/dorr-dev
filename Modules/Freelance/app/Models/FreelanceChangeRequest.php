<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A change to an active agreement, asked by one side and answered by the other (FL-404 / FL-405). */
class FreelanceChangeRequest extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const MAX_FILES = 3;

    public const TYPES = ['extend_days', 'scope', 'milestone_price', 'add_milestone'];

    public const PENDING = 'pending';

    protected $fillable = [
        'uuid', 'agreement_id', 'project_id', 'milestone_id', 'type', 'requested_by', 'requester_id', 'payload', 'reason', 'status', 'respond_by', 'reminded_at', 'answered_at', 'applied_version', 'effect',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceChangeRequest $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'effect' => 'array', 'respond_by' => 'datetime', 'reminded_at' => 'datetime', 'answered_at' => 'datetime', 'applied_version' => 'integer'];
    }

    /** Waiting for an answer, and still in time. */
    public function open(): bool
    {
        return $this->status === self::PENDING && ($this->respond_by === null || $this->respond_by->isFuture());
    }

    /** What the apps show: a request nobody answered in time reads as expired. */
    public function shownStatus(): string
    {
        return $this->status === self::PENDING && ! $this->open() ? 'expired' : $this->status;
    }

    protected function webpSkipCollections(): array
    {
        return ['files'];
    }

    /** A file the request carries (a design, a sketch, a document), kept as uploaded on the private disk. */
    public function addFile(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'files', (string) (($this->getMedia('files')->max('order_column') ?? 0) + 1))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->usingFileName(\Illuminate\Support\Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()))
            ->toMediaCollection('files', self::DISK);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }
}
