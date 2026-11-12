<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Models\Currency;
use App\Repositories\General\LanguageRepository;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An AI subscription plan - a catalog entity like Country: its customer-facing text (name, description, badge,
 * features) lives in ai_plan_translations, `status` is the on/off switch, and deleting moves it to the trash.
 *
 * Back-compat: `$plan->name / description / badge / features` read the current language's translation, and
 * `$plan->is_active` reads `status` - so the chat, wallet notes, notifications and the mobile API keep working.
 * Setting them flat (seeders, tests: `create(['name' => 'Pro'])`) writes that text into every stored language;
 * the admin screen sends `translations[]` instead (TranslatableRepository).
 */
class AiPlan extends Model
{
    use HasTranslations, SearchFilterTrait, SoftDeletes;

    /** Translatable text, settable flat for back-compat (see the class doc). */
    private const TRANSLATED = ['name', 'description', 'badge', 'features'];

    /** The plan text is shown wherever a plan is, so its translations always come along (plans are a handful). */
    protected $with = ['translations'];

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
        'status',
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
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_plan_translations (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $plan): void {
            $plan->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiPlanTranslation::class, 'ai_plan_id');
    }

    protected function translationModel(): string
    {
        return AiPlanTranslation::class;
    }

    /** Open for new subscriptions and renewals: switched on and not in the trash. */
    public function isAvailable(): bool
    {
        return (bool) $this->status && ! $this->trashed();
    }

    public function getNameAttribute(): ?string
    {
        return $this->translatedValue('name');
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->translatedValue('description');
    }

    public function getBadgeAttribute(): ?string
    {
        return $this->translatedValue('badge');
    }

    /**
     * @return list<string>
     */
    public function getFeaturesAttribute(): array
    {
        return array_values($this->translatedValue('features') ?? []);
    }

    public function setNameAttribute(?string $value): void
    {
        $this->queueFlatTranslation('name', $value);
    }

    public function setDescriptionAttribute(?string $value): void
    {
        $this->queueFlatTranslation('description', $value);
    }

    public function setBadgeAttribute(?string $value): void
    {
        $this->queueFlatTranslation('badge', $value);
    }

    /**
     * @param  list<string>|null  $value
     */
    public function setFeaturesAttribute(?array $value): void
    {
        $this->queueFlatTranslation('features', $value === null ? null : array_values($value));
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) ($this->attributes['status'] ?? false);
    }

    public function setIsActiveAttribute(mixed $value): void
    {
        $this->attributes['status'] = (bool) $value;
    }

    /** Current-language value of a translated column, else any language's (never blank while one exists). */
    private function translatedValue(string $field): mixed
    {
        if (! $this->relationLoaded('translation') && ! $this->relationLoaded('translations')) {
            if (! $this->exists) {
                return null;
            }

            $this->load('translations');
        }

        $translation = $this->relationLoaded('translation') && $this->translation
            ? $this->translation
            : ($this->translations->firstWhere('locale', app()->getLocale()) ?? $this->translations->first());

        return $translation?->{$field};
    }

    /**
     * HasTranslations writes one field at a time; a new translation row needs its (NOT NULL) name in the same
     * insert, so write each language's queued fields together.
     */
    protected function syncPendingTranslations(): void
    {
        if ($this->pendingTranslations === []) {
            return;
        }

        $byLocale = [];

        foreach ($this->pendingTranslations as $field => $locales) {
            if (! in_array($field, self::TRANSLATED, true)) {
                continue;
            }

            foreach ($locales as $locale => $value) {
                $byLocale[$locale][$field] = $value;
            }
        }

        $this->pendingTranslations = [];

        foreach ($byLocale as $locale => $values) {
            $existing = $this->translations()->where('locale', $locale)->first();

            if ($existing) {
                $existing->update($values);
            } else {
                $this->translations()->create(['locale' => $locale, 'name' => $values['name'] ?? (string) $this->code] + $values);
            }
        }
    }

    private function queueFlatTranslation(string $field, mixed $value): void
    {
        $locales = LanguageRepository::storableLocaleCodes()
            ?: array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'en')]));

        $this->fillAllTranslations([$field => array_fill_keys($locales, $value)]);
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
