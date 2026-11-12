<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A freelancer plan (FL-L01): what it gives — offers per month, a commission rate, a trial, and (phase 4) a badge, a weight in the
 * «recommended» order (`boost`), the performance numbers (`analytics`: 0 none, 1 basic, 2 full) and room for more ready-made services —
 * and, in `prices`, what it costs in each country. A null number means "what the country's policy says"; the Free plan (rank 0) keeps
 * them all null or off, so a freelancer with no subscription is under the policy as before.
 *
 * FS-211: a plan is sold to one `audience` - a `freelancer` (everything above) or a `team` (a freelancers' team, paid by its Owner). A team
 * plan reuses `offers_monthly` / `offers_unlimited` for the team's monthly offers, and adds `team_members_max` and `team_projects_max`
 * (null = no cap); the freelancer-only perks (commission, badge, boost, analytics, services) are not read for it.
 */
class FreelancePlan extends Model
{
    public const FREE = 'free';

    public const FREELANCER = 'freelancer';

    public const TEAM = 'team';

    public const AUDIENCES = [self::FREELANCER, self::TEAM];

    protected $fillable = [
        'slug', 'audience', 'rank', 'name', 'description', 'offers_monthly', 'offers_unlimited', 'commission_bp', 'trial_days', 'badge', 'boost', 'analytics', 'crm', 'services_max',
        'team_members_max', 'team_projects_max', 'status',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array', 'description' => 'array', 'rank' => 'integer', 'offers_monthly' => 'integer', 'offers_unlimited' => 'boolean',
            'commission_bp' => 'integer', 'trial_days' => 'integer', 'badge' => 'boolean', 'boost' => 'integer', 'analytics' => 'integer', 'crm' => 'integer',
            'services_max' => 'integer', 'team_members_max' => 'integer', 'team_projects_max' => 'integer', 'status' => 'boolean',
        ];
    }

    /** The plans a freelancer can take (a team's are not among them). */
    public function scopeForFreelancers(Builder $query): Builder
    {
        return $query->where('audience', self::FREELANCER);
    }

    /** The plans a team's Owner can take for the team. */
    public function scopeForTeams(Builder $query): Builder
    {
        return $query->where('audience', self::TEAM);
    }

    public function isTeam(): bool
    {
        return $this->audience === self::TEAM;
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FreelancePlanPrice::class, 'plan_id');
    }

    /** FS-705: the catalog features this plan gives beyond its own columns (see `PlanFeatures`). */
    public function features(): HasMany
    {
        return $this->hasMany(FreelancePlanFeature::class, 'plan_id');
    }

    public function isFree(): bool
    {
        return $this->rank === 0;
    }

    /** The plan's name or description in a language (the app's, else Arabic, else English). */
    public function text(string $field, ?string $locale = null): ?string
    {
        $values = (array) $this->{$field};
        $locale ??= app()->getLocale();

        return $values[$locale] ?? $values['ar'] ?? $values['en'] ?? null;
    }
}
