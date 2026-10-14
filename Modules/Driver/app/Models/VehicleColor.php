<?php

namespace Modules\Driver\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vehicle colour (catalog), shown as a swatch and named in each language. */
class VehicleColor extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['hex', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(VehicleColorTranslation::class);
    }

    protected function translationModel(): string
    {
        return VehicleColorTranslation::class;
    }
}
