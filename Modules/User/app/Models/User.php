<?php

namespace Modules\User\Models;

use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Models\Concerns\HasSocialAccounts;
use App\Models\Concerns\HasVerificationCodes;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasMediaTrait, HasRoles, HasSocialAccounts, HasVerificationCodes, Notifiable, SearchFilterTrait;

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

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
