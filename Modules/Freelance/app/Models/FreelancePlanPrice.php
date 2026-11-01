<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a plan costs in one country for one period (`monthly` or `yearly`), in the minor unit of the country's currency. */
class FreelancePlanPrice extends Model
{
    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    public const PERIODS = [self::MONTHLY, self::YEARLY];

    protected $fillable = ['plan_id', 'country_id', 'period', 'price_minor', 'status'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'status' => 'boolean', 'plan_id' => 'integer', 'country_id' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FreelancePlan::class, 'plan_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
