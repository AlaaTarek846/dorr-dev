<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use App\Support\Referral\ReferrableType;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'referrer_type',
        'referrer_id',
        'referred_type',
        'referred_id',
        'referral_code_id',
        'status',
        'registered_at',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'registered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function referrer(): ?Model
    {
        return ReferrableType::find($this->referrer_type, $this->referrer_id);
    }

    public function referred(): ?Model
    {
        return ReferrableType::find($this->referred_type, $this->referred_id);
    }
}
