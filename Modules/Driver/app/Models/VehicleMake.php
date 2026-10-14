<?php

namespace Modules\Driver\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vehicle make (catalog). */
class VehicleMake extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(VehicleMakeTranslation::class);
    }

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class, 'make_id');
    }

    protected function translationModel(): string
    {
        return VehicleMakeTranslation::class;
    }
}
