<?php

namespace Modules\Driver\Models;

use App\Enums\Gender;
use App\Models\Concerns\HasNotificationDevices;
use App\Models\Concerns\HasReferralCode;
use App\Models\Concerns\HasVerificationCodes;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SendsPhoneOtp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Modules\Driver\Enums\DriverResidency;
use Modules\Driver\Enums\DriverStatus;
use Modules\Wallet\Concerns\HasWallets;
use Spatie\MediaLibrary\HasMedia;

/**
 * A driver: their own account on the `driver_api` guard (docs/rides-plan.md §4.1) — not a
 * Provider. One account for every DORR service they work in; each service has its own
 * eligibility (DriverServiceEligibility).
 */
class Driver extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasMediaTrait, HasNotificationDevices, HasReferralCode, HasVerificationCodes, HasWallets, Notifiable, SendsPhoneOtp, SoftDeletes;

    protected $table = 'drivers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'name_ar',
        'name_en',
        'phone',
        'phone_verified_at',
        'email',
        'gender',
        'date_of_birth',
        'national_id',
        'id_type',
        'residency',
        'nationality_country_id',
        'country_id',
        'city_id',
        'locale',
        'status',
        'status_reason',
        'registered_by',
        'registered_by_admin_id',
        'activated_at',
        'onboarding_ends_at',
        'last_active_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['remember_token'];

    protected static function booted(): void
    {
        static::creating(function (Driver $driver): void {
            $driver->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'activated_at' => 'datetime',
            'onboarding_ends_at' => 'datetime',
            'last_active_at' => 'datetime',
            'status' => DriverStatus::class,
            'gender' => Gender::class,
            'residency' => DriverResidency::class,
            'city_id' => 'integer',
        ];
    }

    /** Only a blocked account is turned away at sign-in; the others sign in and see why they can't work (D-9). */
    public function canSignIn(): bool
    {
        return $this->status !== DriverStatus::Blocked;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_country_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function activeVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->where('is_active', true);
    }

    public function serviceEligibility(): HasMany
    {
        return $this->hasMany(DriverServiceEligibility::class);
    }

    public function presence(): HasOne
    {
        return $this->hasOne(DriverPresence::class);
    }
}
