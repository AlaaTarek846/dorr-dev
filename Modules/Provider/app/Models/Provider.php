<?php

namespace Modules\Provider\Models;

use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Models\Concerns\HasNotificationDevices;
use App\Models\Concerns\HasReferralCode;
use App\Models\Concerns\HasSocialAccounts;
use App\Models\Concerns\HasVerificationCodes;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use App\Traits\SendsPhoneOtp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Wallet\Concerns\HasWallets;
use Spatie\MediaLibrary\HasMedia;

class Provider extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasMediaTrait, HasNotificationDevices, HasReferralCode, HasSocialAccounts, HasVerificationCodes, HasWallets, Notifiable, SearchFilterTrait, SendsPhoneOtp, SoftDeletes;

    protected $table = 'providers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'gender',
        'country_id',
        'password',
        'status',
        'email_verified_at',
        'phone_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'gender' => Gender::class,
        ];
    }

    public function canSignIn(): bool
    {
        return $this->status !== UserStatus::Blocked;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    /** Seats this person holds in establishments: their own and the ones they joined (docs/moving_business.md §14). */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
