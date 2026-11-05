<?php

namespace Modules\WatchEarn\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\User\Models\User;

/**
 * The advertiser's ad wallet in one country (WE-011, D12): same currency as their wallet. Holds the FREE balance,
 * by the wallet bucket each amount came from; what an ad reserved is on the ad. Only AdWalletService changes it.
 */
class AdWallet extends Model
{
    protected $table = 'we_ad_wallets';

    protected $fillable = ['user_id', 'country_id', 'currency_id', 'reward_minor', 'spend_only_minor', 'withdrawable_minor'];

    protected function casts(): array
    {
        return ['reward_minor' => 'integer', 'spend_only_minor' => 'integer', 'withdrawable_minor' => 'integer'];
    }

    public function freeMinor(): int
    {
        return $this->reward_minor + $this->spend_only_minor + $this->withdrawable_minor;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class, 'ad_wallet_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AdWalletEntry::class, 'ad_wallet_id');
    }
}
