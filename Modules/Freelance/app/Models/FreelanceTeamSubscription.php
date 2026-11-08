<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * FS-211: a freelancers' team's subscription to a team plan. The same life as a freelancer's (`FreelanceSubscription`: `trialing`, `active`,
 * `past_due`, `expired`, a downgrade waiting in `pending_*`) with the team as the subject and the Owner who pays - out of his own wallet
 * (D7) - in `paid_by_freelancer_id`. A team has at most one live row.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int $paid_by_freelancer_id
 * @property int $plan_id
 * @property string $status
 * @property bool $auto_renew
 */
class FreelanceTeamSubscription extends Model
{
    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const EXPIRED = 'expired';

    public const LIVE = [self::TRIALING, self::ACTIVE, self::PAST_DUE];

    protected $table = 'freelance_team_subscriptions';

    protected $fillable = [
        'uuid', 'team_id', 'paid_by_freelancer_id', 'plan_id', 'country_id', 'currency_code', 'period', 'price_minor', 'status', 'auto_renew', 'started_at', 'period_start',
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(FreelanceTeam::class, 'team_id')->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'paid_by_freelancer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'pending_plan_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FreelanceTeamSubscriptionInvoice::class, 'team_subscription_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /**
     * Whether it still gives its plan: a trial or a paid period until it ends - and past its end while it waits for the renewal (auto-renew
     * on) - and a failed renewal through its grace days only. Same rule as a freelancer's (`FreelancePlans::gives`).
     */
    public function gives(): bool
    {
        if ($this->status === self::PAST_DUE) {
            return $this->grace_until !== null && $this->grace_until->isFuture();
        }

        return $this->isLive() && ($this->period_end->isFuture() || $this->auto_renew);
    }
}
