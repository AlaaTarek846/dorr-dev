<?php

namespace Modules\Admin\Models;

use App\Enums\Gender;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasMediaTrait, HasRoles, SearchFilterTrait, SoftDeletes;

    protected string $guard_name = 'admin_api';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'gender',
        'country_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
            'gender' => Gender::class,
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(AdminService::class);
    }
}
