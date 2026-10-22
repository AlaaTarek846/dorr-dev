<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Freelance\Enums\FreelanceOfferStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A freelancer's offer on a project (FL-301). Only its freelancer and the project's client ever see it; its files live on the
 * private disk and are handed out through their signed-in endpoints.
 */
class FreelanceOffer extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    protected $fillable = [
        'uuid', 'project_id', 'freelancer_id', 'price_minor', 'currency_code', 'duration_days', 'details', 'status', 'status_reason',
        'commission_minor', 'net_minor', 'fees', 'free', 'viewed_at', 'accepted_at', 'withdrawn_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceOffer $o) => $o->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'status' => FreelanceOfferStatus::class,
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'commission_minor' => 'integer',
            'net_minor' => 'integer',
            'fees' => 'array',
            'free' => 'boolean',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected function webpSkipCollections(): array
    {
        return ['attachments'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(FreelanceOfferEvent::class, 'offer_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FreelanceOfferRevision::class, 'offer_id');
    }

    public function addAttachment(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'attachments', (string) (($this->getMedia('attachments')->max('order_column') ?? 0) + 1))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection('attachments', self::DISK);
    }
}
