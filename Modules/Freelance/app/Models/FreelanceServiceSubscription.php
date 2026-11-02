<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * A client's monthly subscription to a ready-made service (FL-L05). Every month is an order of its own (`cycle`), at the price and terms
 * kept here from the day it began. Its status only moves through ServiceSubscriptions.
 */
class FreelanceServiceSubscription extends Model
{
    /** The first month waits for the freelancer to accept it. */
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    protected $fillable = [
        'uuid', 'service_id', 'client_id', 'freelancer_id', 'level', 'snapshot', 'price_minor', 'currency_code', 'delivery_days', 'revisions',
        'note', 'status', 'cycle', 'started_at', 'period_ends_at', 'renew_reminded_at', 'cancel_requested_at', 'cancel_by', 'ends_at',
        'ended_at', 'end_reason',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceServiceSubscription $s) => $s->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'price_minor' => 'integer', 'delivery_days' => 'integer', 'revisions' => 'integer', 'cycle' => 'integer',
            'started_at' => 'datetime', 'period_ends_at' => 'datetime', 'renew_reminded_at' => 'datetime', 'cancel_requested_at' => 'datetime',
            'ends_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    /** Its months, newest first. */
    public function orders(): HasMany
    {
        return $this->hasMany(FreelanceServiceOrder::class, 'subscription_id')->orderByDesc('cycle');
    }

    public function currentOrder(): HasOne
    {
        return $this->hasOne(FreelanceServiceOrder::class, 'subscription_id')->ofMany('cycle', 'max');
    }

    /** No more months after the current one: an ending asked with notice lands on its end. */
    public function endsWithThisMonth(): bool
    {
        return $this->ends_at !== null && $this->period_ends_at !== null && $this->ends_at->lte($this->period_ends_at);
    }
}
