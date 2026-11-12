<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Traits\SearchFilterTrait;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A site hosting plan (monthly / yearly, price per country). A catalog entity like Country: its customer-facing
 * text (name, description) lives in ai_site_hosting_plan_translations, `status` is the on/off switch, and deleting
 * moves it to the trash.
 *
 * Back-compat (HasFlatTranslatedText): `$plan->name / description` read the current language, `$plan->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiSiteHostingPlan extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    protected $table = 'ai_site_hosting_plans';

    /** The plan text is shown wherever a plan is, so its translations always come along (plans are a handful). */
    protected $with = ['translations'];

    protected $fillable = ['name', 'code', 'period', 'description', 'status', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to the translations table (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $plan): void {
            $plan->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiSiteHostingPlanTranslation::class, 'ai_site_hosting_plan_id');
    }

    protected function translationModel(): string
    {
        return AiSiteHostingPlanTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
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
