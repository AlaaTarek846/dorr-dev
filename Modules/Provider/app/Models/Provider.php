<?php

namespace Modules\Provider\Models;

use App\Enums\Gender;
use App\Models\Country;
use App\Models\Concerns\HasSocialAccounts;
use App\Models\Concerns\HasVerificationCodes;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Enums\UserStatus;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;

class Provider extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasMediaTrait, HasSocialAccounts, HasVerificationCodes, Notifiable, SearchFilterTrait;

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

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }
}
