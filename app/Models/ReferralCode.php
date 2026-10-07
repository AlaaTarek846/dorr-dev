<?php

namespace App\Models;

use App\Support\Referral\ReferrableType;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    use SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'referrable_type',
        'referrable_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function owner(): ?Model
    {
        return ReferrableType::find($this->referrable_type, $this->referrable_id);
    }
}
