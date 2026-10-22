<?php

namespace Modules\Freelance\Models;

use App\Enums\Gender;
use App\Models\Concerns\HasNotificationDevices;
use App\Models\Concerns\HasVerificationCodes;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SendsPhoneOtp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Wallet\Concerns\HasWallets;
use Spatie\MediaLibrary\HasMedia;

/**
 * A freelancer («محترف»): their own account on the `freelance_api` guard (docs/freelance_tasks.md
 * FL-003) — not a Provider and not a User, the same way a driver isn't. Its wallet (FL-005, one per country, like a driver's) is read and topped up through Modules/Wallet; its chat
 * (FL-006) is Modules/Freelance's own, and push devices (FL-007) are the shared notification devices.
 */
class Freelancer extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasMediaTrait, HasNotificationDevices, HasVerificationCodes, HasWallets, Notifiable, SendsPhoneOtp, SoftDeletes;

    protected $table = 'freelancers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'headline',
        'bio',
        'years_of_experience',
        'contact_languages',
        'city',
        'residency',
        'phone',
        'phone_verified_at',
        'email',
        'email_verified_at',
        'gender',
        'date_of_birth',
        'country_id',
        'locale',
        'status',
        'status_reason',
        'verified_at',
        'terms_accepted_at',
        'terms_version',
        'last_active_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['remember_token'];

    protected static function booted(): void
    {
        static::creating(function (Freelancer $freelancer): void {
            $freelancer->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'contact_languages' => 'array',
            'years_of_experience' => 'integer',
            'date_of_birth' => 'date',
            'terms_accepted_at' => 'datetime',
            'last_active_at' => 'datetime',
            'status' => FreelancerStatus::class,
            'gender' => Gender::class,
            'country_id' => 'integer',
        ];
    }

    /** Only a blocked account is turned away at sign-in; the others sign in and see why. */
    public function canSignIn(): bool
    {
        return $this->status !== FreelancerStatus::Blocked;
    }

    public function hasAcceptedTerms(): bool
    {
        return $this->terms_accepted_at !== null
            && $this->terms_version === (string) config('freelance.terms_version');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(FreelanceSpecialty::class, 'freelancer_specialties', 'freelancer_id', 'freelance_specialty_id')->withPivot('is_primary');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(FreelanceSkill::class, 'freelancer_skills', 'freelancer_id', 'freelance_skill_id')->withPivot('level');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(FreelancerExperience::class);
    }

    /** The projects this freelancer saved (FL-207). */
    public function projectFavorites(): HasMany
    {
        return $this->hasMany(FreelanceProjectFavorite::class);
    }

    public function availability(): HasOne
    {
        return $this->hasOne(FreelancerAvailability::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(FreelancerTimeOff::class);
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(FreelancerPortfolioItem::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(FreelancerCertificate::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(FreelancerDevice::class);
    }
}
