<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'min_topup_minor',
        'max_topup_minor',
        'min_withdrawal_minor',
        'max_withdrawal_minor',
        'transfer_max_per_transaction_minor',
        'transfer_max_per_day_minor',
        'transfer_max_per_month_minor',
        'transfers_enabled',
        'min_allowed_balance_provider_minor',
        'min_allowed_balance_user_minor',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'min_topup_minor' => 'integer',
            'max_topup_minor' => 'integer',
            'min_withdrawal_minor' => 'integer',
            'max_withdrawal_minor' => 'integer',
            'transfer_max_per_transaction_minor' => 'integer',
            'transfer_max_per_day_minor' => 'integer',
            'transfer_max_per_month_minor' => 'integer',
            'transfers_enabled' => 'boolean',
            'min_allowed_balance_provider_minor' => 'integer',
            'min_allowed_balance_user_minor' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
