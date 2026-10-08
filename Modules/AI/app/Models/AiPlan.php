<?php

namespace Modules\AI\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPlan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'usage_minutes',
        'cooldown_minutes',
        'image_daily_limit',
        'video_daily_limit',
        'video_max_seconds',
        'site_projects_limit',
        'site_daily_generations',
        'duration_days',
        'price',
        'original_price',
        'currency_id',
        'badge',
        'is_featured',
        'features',
        'is_trial',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'image_daily_limit' => 'integer',
            'video_daily_limit' => 'integer',
            'video_max_seconds' => 'integer',
            'site_projects_limit' => 'integer',
            'site_daily_generations' => 'integer',
            'duration_days' => 'integer',
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'is_trial' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'features' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AiSubscription::class, 'plan_id');
    }

    /**
     * Named currencyRef (not currency()) on purpose - the `currency`
     * accessor below returns the plain code string every existing caller
     * already expects ($this->currency, AiPlanResource, the purchase/
     * billing services, the notifier...), so the relation needs a
     * different name to avoid the accessor/relation colliding on the
     * same `currency` key.
     */
    public function currencyRef(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Back-compat shim for the currency->currency_id migration: every
     * existing read of $plan->currency (resolvedPriceFor's fallback
     * branch, AiPlanResource, AiSubscriptionPurchaseService::logPayment(),
     * AiSubscriptionNotifier, AiPlanPriceController's plan summary...)
     * expects a plain currency code string, not a Currency model - so this
     * stays a string-returning accessor instead of exposing the relation
     * under the `currency` key directly.
     */
    public function getCurrencyAttribute(): ?string
    {
        return $this->currencyRef?->code;
    }

    /**
     * Per-country price overrides (docs: "اسعار الباقات على حسب البلد وبرده
     * العمله"). Most plans will only have a handful of these, if any -
     * resolvedPriceFor() is what everything else (purchase service,
     * customer-facing plans() endpoint) should call instead of reading
     * price/currency directly, so the fallback rule lives in one place.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AiPlanPrice::class, 'plan_id');
    }

    /**
     * Whole-percent savings when original_price is set and genuinely higher
     * than the live price - null otherwise (no discount to advertise).
     */
    public function discountPercent(): ?int
    {
        if ($this->original_price === null || (float) $this->original_price <= (float) $this->price) {
            return null;
        }

        return (int) round((1 - ((float) $this->price / (float) $this->original_price)) * 100);
    }

    /**
     * Resolves the price/currency this plan actually costs for a given
     * country: a country-specific override when the admin has set one,
     * otherwise the plan's own base price/currency columns (the fallback
     * the business chose - a country with no explicit price simply uses
     * the plan's normal price, it is never blocked from subscribing).
     *
     * Efficient in a list: eager-load prices scoped to the country first
     * (`with(['prices' => fn ($q) => $q->where('country_id', $country->id)])`)
     * and this reads the already-loaded relation instead of querying again.
     * Called with a single plan (purchase/renewal flow) it queries directly.
     *
     * @return array{price: float, original_price: ?float, currency: string, is_country_specific: bool}
     */
    public function resolvedPriceFor(?Country $country): array
    {
        $override = null;

        if ($country !== null) {
            $override = $this->relationLoaded('prices')
                ? $this->prices->firstWhere('country_id', $country->id)
                : $this->prices()->where('country_id', $country->id)->with('currency')->first();
        }

        if ($override !== null) {
            return [
                'price' => (float) $override->price,
                'original_price' => $override->original_price !== null ? (float) $override->original_price : null,
                'currency' => $override->currency?->code ?? $this->currency,
                'is_country_specific' => true,
            ];
        }

        return [
            'price' => (float) $this->price,
            'original_price' => $this->original_price !== null ? (float) $this->original_price : null,
            'currency' => $this->currency,
            'is_country_specific' => false,
        ];
    }
}
