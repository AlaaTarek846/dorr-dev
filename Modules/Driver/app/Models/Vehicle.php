<?php

namespace Modules\Driver\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A driver's vehicle (photos: media collection `photos`). The plate is kept the way Wasl wants it:
 * Arabic letters, digits, the plate type code and the registration's sequence number.
 * One active vehicle per driver — enforced in the service layer (no partial unique on SQLite).
 */
class Vehicle extends Model implements HasMedia
{
    use HasMediaTrait, SoftDeletes;

    protected $fillable = [
        'uuid', 'driver_id', 'country_id', 'make_id', 'model_id', 'color_id', 'year', 'body_type', 'seats',
        'plate_letters', 'plate_number', 'plate_type', 'sequence_number', 'features', 'status', 'is_active',
        'approved_by_admin_id', 'approved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle): void {
            $vehicle->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'seats' => 'integer',
            'plate_type' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'body_type' => VehicleBodyType::class,
            'status' => VehicleStatus::class,
        ];
    }

    /** @return list<string> The plate's letters one by one (Wasl wants left / middle / right). */
    public function plateLetters(): array
    {
        $letters = (string) preg_replace('/\s+/u', '', (string) $this->plate_letters);

        return mb_str_split($letters);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(VehicleColor::class, 'color_id');
    }
}
