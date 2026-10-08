<?php

namespace Modules\AI\Models;

use App\Models\Country;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSiteHostingPlan extends Model
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    protected $table = 'ai_site_hosting_plans';

    protected $fillable = ['name', 'code', 'period', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AiSiteHostingPlanPrice::class, 'plan_id');
    }

    public function hostings(): HasMany
    {
        return $this->hasMany(AiSiteHosting::class, 'plan_id');
    }

    /** One period later than $from. */
    public function periodEnd(CarbonInterface $from): CarbonInterface
    {
        return $this->period === self::PERIOD_YEARLY ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    /**
     * @return array{price: float, currency: string}|null null = not sold in this country
     */
    public function priceFor(?Country $country): ?array
    {
        if ($country === null) {
            return null;
        }

        $row = $this->prices()->where('country_id', $country->id)->with('currency')->first();

        if ($row === null || $row->currency === null) {
            return null;
        }

        return ['price' => (float) $row->price, 'currency' => (string) $row->currency->code];
    }
}
