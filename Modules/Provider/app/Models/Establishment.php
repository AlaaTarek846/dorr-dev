<?php

namespace Modules\Provider\Models;

use App\Models\Country;
use App\Models\ServiceCategory;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Enums\EstablishmentType;
use Modules\Wallet\Concerns\HasWallets;
use Spatie\MediaLibrary\HasMedia;

/**
 * An individual or a company offering services on Dorr (docs/moving_business.md §5). Owns its branches, members,
 * vehicles, documents and its own wallet (alias `establishment`), separate from the people working in it.
 */
class Establishment extends Model implements HasMedia
{
    use HasMediaTrait, HasWallets, SoftDeletes;

    protected $table = 'provider_establishments';

    protected $fillable = [
        'uuid', 'owner_id', 'type', 'name', 'country_id', 'commercial_register_no', 'tax_number',
        'status', 'status_reason', 'submitted_at', 'reviewed_by_admin_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EstablishmentType::class,
            'status' => EstablishmentStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'owner_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'provider_establishment_services', 'establishment_id', 'service_category_id')
            ->withPivot('status')->withTimestamps();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'establishment_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'establishment_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'establishment_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class, 'establishment_id');
    }

    public function isApproved(): bool
    {
        return $this->status === EstablishmentStatus::Approved;
    }

    public function offersService(int $serviceCategoryId): bool
    {
        return $this->services()->where('service_categories.id', $serviceCategoryId)
            ->wherePivot('status', true)->exists();
    }
}
