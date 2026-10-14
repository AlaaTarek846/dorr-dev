<?php

namespace Modules\Driver\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Driver\Enums\VehicleBodyType;

/** A vehicle model (catalog): its body type and seats, and whether it counts as luxury (VIP). */
class VehicleModel extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['make_id', 'body_type', 'seats', 'is_luxury', 'status'];

    protected function casts(): array
    {
        return [
            'body_type' => VehicleBodyType::class,
            'seats' => 'integer',
            'is_luxury' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(VehicleModelTranslation::class);
    }

    protected function translationModel(): string
    {
        return VehicleModelTranslation::class;
    }
}
