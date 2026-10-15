<?php

namespace Modules\Driver\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Driver\Enums\DocumentStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * One uploaded document (files: media collections `front` / `back`). An approved one becomes
 * `replaced` when a newer one of the same type is approved — the history stays.
 */
class DriverDocument extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = ['uuid', 'driver_id', 'vehicle_id', 'document_type_id', 'number', 'expires_at', 'status', 'rejection_reason', 'reviewed_by_admin_id', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(function (DriverDocument $document): void {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'status' => DocumentStatus::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DriverDocumentType::class, 'document_type_id');
    }
}
