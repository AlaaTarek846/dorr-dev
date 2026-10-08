<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One winner of a contest and their prize: pending · review (waits for the admin) · paid · rejected. */
class SportsContestWinner extends Model
{
    protected $fillable = ['contest_id', 'owner_type', 'owner_id', 'country_id', 'points', 'rank', 'prize_type', 'amount_minor', 'currency_code', 'coupon_id', 'wallet_transaction_id', 'status', 'note', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount_minor' => 'integer', 'points' => 'integer', 'rank' => 'integer'];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(SportsContest::class, 'contest_id');
    }
}
