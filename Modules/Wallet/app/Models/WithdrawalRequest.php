<?php

namespace Modules\Wallet\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Wallet\Enums\WithdrawalRequestStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A payout request. The money is reserved (wallet_holds) the moment it is
 * created; approval captures the hold into a real `withdrawal` ledger row,
 * rejection releases it. The transfer receipt lives in a *private* media
 * collection — it carries bank details and must not be reachable by URL.
 */
class WithdrawalRequest extends Model implements HasMedia
{
    use HasMediaTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id', 'withdrawal_method_id', 'amount_minor', 'status', 'note', 'rejection_reason',
        'reviewed_by', 'reviewed_at', 'hold_id', 'idempotency_key', 'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalRequestStatus::class,
            'amount_minor' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')->singleFile()->useDisk('local');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(WithdrawalMethod::class, 'withdrawal_method_id')->withTrashed();
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(WalletHold::class, 'hold_id');
    }
}
