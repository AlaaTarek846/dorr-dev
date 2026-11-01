<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One freelancer's subscription to a plan (FL-L01). `trialing`, `active` and `past_due` (a renewal failed, inside the grace days) give the
 * plan's benefits; `expired` does not. Turning auto-renew off keeps it `active` until the period ends. A downgrade waits in `pending_*`.
 */
class FreelanceSubscription extends Model
{
    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const EXPIRED = 'expired';

    public const LIVE = [self::TRIALING, self::ACTIVE, self::PAST_DUE];

    protected $fillable = [
        'uuid', 'freelancer_id', 'plan_id', 'country_id', 'currency_code', 'period', 'price_minor', 'status', 'auto_renew', 'started_at', 'period_start',
        'period_end', 'trial_ends_at', 'canceled_at', 'ended_at', 'pending_plan_id', 'pending_period', 'grace_until', 'last_attempt_at', 'reminded_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer', 'auto_renew' => 'boolean', 'started_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime',
            'trial_ends_at' => 'datetime', 'canceled_at' => 'datetime', 'ended_at' => 'datetime', 'grace_until' => 'datetime',
            'last_attempt_at' => 'datetime', 'reminded_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    /**
     * The ones that give their plan right now (as `FreelancePlans::gives`): a trial or paid period until it ends — and past its end while it
     * waits for the renewal (auto-renew on) — and a failed renewal through its grace days only.
     */
    public function scopeGiving(Builder $query): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->where(fn (Builder $a) => $a->whereIn('status', [self::TRIALING, self::ACTIVE])->where(fn (Builder $p) => $p->where('period_end', '>', now())->orWhere('auto_renew', true)))
            ->orWhere(fn (Builder $d) => $d->where('status', self::PAST_DUE)->where('grace_until', '>', now())));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'pending_plan_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FreelanceSubscriptionInvoice::class, 'subscription_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }
}
