<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;
use Modules\Wallet\Support\OwnerType;

/**
 * "People I've already sent money to, in this country" — written automatically on every successful
 * transfer (see TransferService::send()), never by hand. A quick-pick list for the transfer screen,
 * nothing more; it grants no special permission.
 */
class WalletBeneficiary extends Model
{
    /** How recent a phone-number change still earns the sender a warning before paying this person
     *  (wallet policy bend 10 — "تحذير عند تغيّر رقم المستفيد مؤخرًا"). Shared by the transfer lookup
     *  and this list, so the two never disagree about what "recently" means. */
    public const PHONE_CHANGE_WARNING_DAYS = 14;

    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'country_id', 'beneficiary_user_id', 'first_added_at', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'first_added_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }
}
