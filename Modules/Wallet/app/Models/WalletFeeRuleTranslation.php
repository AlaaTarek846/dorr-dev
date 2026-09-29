<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletFeeRuleTranslation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['wallet_fee_rule_id', 'locale', 'name', 'description'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WalletFeeRule::class, 'wallet_fee_rule_id');
    }
}
