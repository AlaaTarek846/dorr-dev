<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A freelancer plan (FL-L01): what it gives — offers per month, a commission rate, a trial, and (phase 4) a badge, a weight in the
 * «recommended» order (`boost`), the performance numbers (`analytics`: 0 none, 1 basic, 2 full) and room for more ready-made services —
 * and, in `prices`, what it costs in each country. A null number means "what the country's policy says"; the Free plan (rank 0) keeps
 * them all null or off, so a freelancer with no subscription is under the policy as before.
 */
class FreelancePlan extends Model
{
    public const FREE = 'free';

    protected $fillable = [
        'slug', 'rank', 'name', 'description', 'offers_monthly', 'offers_unlimited', 'commission_bp', 'trial_days', 'badge', 'boost', 'analytics', 'services_max', 'status',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array', 'description' => 'array', 'rank' => 'integer', 'offers_monthly' => 'integer', 'offers_unlimited' => 'boolean',
            'commission_bp' => 'integer', 'trial_days' => 'integer', 'badge' => 'boolean', 'boost' => 'integer', 'analytics' => 'integer',
            'services_max' => 'integer', 'status' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FreelancePlanPrice::class, 'plan_id');
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
