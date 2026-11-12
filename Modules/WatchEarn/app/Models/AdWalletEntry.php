<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move in or out of an ad wallet, split by bucket (the advertiser's statement). */
class AdWalletEntry extends Model
{
    public const FUND = 'fund';

    public const TOPUP = 'topup';

    public const RETURN = 'return';

    public const RESERVE = 'reserve';

    public const RELEASE = 'release';

    public const UPDATED_AT = null;

    protected $table = 'we_ad_wallet_entries';

    protected $fillable = ['ad_wallet_id', 'ad_id', 'type', 'reward_minor', 'spend_only_minor', 'withdrawable_minor', 'amount_minor', 'free_after_minor', 'operation_id', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return [
            'reward_minor' => 'integer', 'spend_only_minor' => 'integer', 'withdrawable_minor' => 'integer',
            'amount_minor' => 'integer', 'free_after_minor' => 'integer', 'created_at' => 'datetime',
        ];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
