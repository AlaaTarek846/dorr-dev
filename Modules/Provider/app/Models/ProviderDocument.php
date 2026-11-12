<?php

namespace Modules\Provider\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Provider\Enums\DocumentStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * An uploaded paper or vehicle photo, on the establishment, a vehicle or a member (their driving licence). Files are
 * in the media collections `front` and `back`. A newer approved one marks the older one replaced.
 */
class ProviderDocument extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $table = 'provider_documents';

    protected $fillable = [
        'uuid', 'establishment_id', 'subject_type', 'subject_id', 'document_type_id', 'number', 'expires_on',
        'status', 'rejection_reason', 'reviewed_by_admin_id', 'reviewed_at', 'expiry_alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
            'expiry_alerted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function isValid(): bool
    {
        return $this->status === DocumentStatus::Approved
            && ($this->expires_on === null || $this->expires_on->endOfDay()->isFuture());
    }
}
