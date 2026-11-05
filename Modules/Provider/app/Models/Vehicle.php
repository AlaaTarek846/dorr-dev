<?php

namespace Modules\Provider\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Provider\Enums\VehicleStatus;

/** A vehicle of an establishment. It sends offers only once the admin approved it and its papers and photos are valid (§6). */
class Vehicle extends Model
{
    use SoftDeletes;

    protected $table = 'provider_vehicles';

    protected $fillable = [
        'uuid', 'establishment_id', 'branch_id', 'vehicle_type_id', 'country_id', 'plate_letters', 'plate_number',
        'make', 'model', 'color', 'year', 'status', 'status_reason', 'reviewed_by_admin_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['status' => VehicleStatus::class, 'year' => 'integer', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class, 'subject_id')->where('subject_type', 'vehicle');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', VehicleStatus::Approved->value);
    }
}
