<?php

namespace Modules\Rides\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\Rides\Enums\PricingStrategy;
use Modules\Rides\Models\DriverPointsRule;
use Modules\Rides\Models\RideCancelReason;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideRatingTag;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Models\RideTaxRule;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * The 11 ride categories (only Economy active — phase 1), Saudi Arabia's starting rules, VAT on
 * the commission carried by the driver (Jawad), Riyadh's Wasl name, reasons, tags and points.
 * Every price here is an EXAMPLE the admin must set before launch. Safe to run again.
 */
class RidesDatabaseSeeder extends Seeder
{
    /** slug => [strategy, capacity max, colour, ar, en, ar tagline, en tagline] */
    private const CATEGORIES = [
        'economy' => ['distance', 4, '#1E88E5', 'الاقتصادية', 'Economy', 'رحلتك اليومية بسعر مناسب', 'Everyday rides at a fair price'],
        'with_me' => ['distance', 1, '#00897B', 'أنا معك', 'With Me', 'على طريق الكابتن — راكب واحد', 'Along the captain\'s way — one rider'],
        'ladies' => ['distance', 4, '#D81B60', 'لكِ سيدتي', 'For Her', 'راكبة مع كابتن ست', 'Women riders with women captains'],
        'vip' => ['distance', 4, '#212121', 'VIP', 'VIP', 'مركبات فخمة وكباتن مميزين', 'Luxury cars, top captains'],
        'offers' => ['bid', 4, '#F4511E', 'العروض', 'Offers', 'حدد سعرك والكباتن يعرضوا', 'Name your price, captains offer'],
        'accessible' => ['distance', 4, '#5E35B1', 'ذوو الاحتياجات الخاصة', 'Accessible', 'مركبات مجهزة وكابتن مؤهل', 'Equipped vehicles, trained captains'],
        'family' => ['trip_flat', 7, '#43A047', 'العائلية', 'Family', 'لحد 7 ركاب', 'Up to 7 riders'],
        'intercity' => ['distance', 4, '#6D4C41', 'بين المدن', 'Intercity', 'من مدينة لمدينة', 'City to city'],
        'bus' => ['seat', 50, '#3949AB', 'الرحلات المجدولة', 'Scheduled buses', 'مقعدك محجوز وتذكرة QR', 'Your seat, a QR ticket'],
        'motorcycle' => ['time', 1, '#FB8C00', 'الدراجات النارية', 'Motorcycle', 'بالساعة', 'By the hour'],
        'marine' => ['time', 12, '#00ACC1', 'المراكب واليخوت', 'Boats & yachts', 'رحلة بحرية', 'Out on the water'],
    ];

    public function run(): void
    {
        $this->categories();

        $saudi = Country::query()->where('code', 'SA')->first();
        if ($saudi === null) {
            return;
        }

        $this->saudiRules($saudi);
        $this->reasonsAndTags();
        $this->points();
    }

    private function categories(): void
    {
        $sort = 0;
        foreach (self::CATEGORIES as $slug => [$strategy, $capacity, $color, $ar, $en, $taglineAr, $taglineEn]) {
            // withTrashed: a category the admin deleted is left deleted, not re-created (the slug is unique).
            $category = RideCategory::withTrashed()->firstOrCreate(['slug' => $slug], [
                'pricing_strategy' => PricingStrategy::from($strategy),
                'capacity_min' => 1,
                'capacity_max' => $capacity,
                'eligibility' => $this->eligibility($slug),
                'flags' => [
                    'cash_allowed' => $slug !== 'bus',
                    'surge_allowed' => ! in_array($slug, ['motorcycle', 'offers'], true),
                    'open_destination_allowed' => $slug === 'economy',
                    'multi_stop_allowed' => in_array($slug, ['economy', 'vip', 'family', 'ladies'], true),
                    'requires_schedule' => $slug === 'bus',
                ],
                'color' => $color,
                // Phase 1 is Economy only; the rest are switched on in their phases.
                'status' => $slug === 'economy',
                'sort_order' => ++$sort,
            ]);
            $this->translate($category, [
                'ar' => ['name' => $ar, 'tagline' => $taglineAr],
                'en' => ['name' => $en, 'tagline' => $taglineEn],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function eligibility(string $slug): array
    {
        return match ($slug) {
            'ladies' => ['driver_gender' => 'female', 'rider_gender' => 'female'],
            'vip' => ['luxury_only' => true, 'min_year' => 2021],
            'family' => ['min_seats' => 6, 'body_types' => ['suv', 'van', 'minibus']],
            'accessible' => ['required_features' => ['wheelchair_ramp']],
            'bus' => ['body_types' => ['bus', 'minibus']],
            'motorcycle' => ['body_types' => ['motorcycle']],
            'marine' => ['body_types' => ['boat', 'yacht']],
            default => ['body_types' => ['sedan', 'suv', 'van']],
        };
    }

    private function saudiRules(Country $saudi): void
    {
        $economy = RideCategory::query()->where('slug', 'economy')->first();
        if ($economy === null) {
            return;
        }
        $policies = app(RidePolicies::class);

        // Country-wide numbers: Jawad's defaults (rides.policy_defaults) apply; nothing to override yet.
        $policies->save($saudi->id, null, null, null, ['pricing_mode' => 'upfront']);

        // Economy's prices — EXAMPLES (the Wireflow shows ~35 SAR for 7.4 km); the admin sets the real ones.
        $policies->save($saudi->id, null, null, $economy->id, [
            'price.base_minor' => 600,
            'price.per_km_minor' => 180,
            'price.per_min_minor' => 30,
            'price.min_fare_minor' => 1500,
            'price.commission_type' => 'percent',
            'price.commission_value' => 2000,
            'price.cancel_fee_minor' => 1000,
        ]);

        // VAT 15% on the app's commission, carried by the driver — exactly Jawad's rule.
        $vat = RideTaxRule::query()->firstOrCreate(['country_id' => $saudi->id, 'code' => 'vat'], [
            'base' => 'commission',
            'rate_bp' => 1500,
            'borne_by' => 'driver',
            'effective_from' => now()->startOfDay(),
            'status' => true,
        ]);
        $this->translate($vat, [
            'ar' => ['label' => 'ضريبة القيمة المضافة على عمولة التطبيق'],
            'en' => ['label' => 'VAT on the app commission'],
        ]);

        // The one name known to be accepted by Wasl (Jawad sends it in production). The rest of the
        // official list comes from ELM / Wasl — never typed from memory (docs/rides-structure.md §7.2).
        RideRegulatorCity::query()->firstOrCreate(
            ['regulator' => RideRegulatorCity::WASL, 'official_name' => 'الرياض'],
            ['country_id' => $saudi->id, 'city_id' => $this->discoverCityId($saudi->id, 'الرياض'), 'status' => true],
        );

        // Switched on for Saudi Arabia (review before launch): the service, Economy and phase-1 features.
        foreach (['rides', 'category.economy', 'multi_stop', 'open_destination', 'cash', 'wallet', 'card', 'tips'] as $feature) {
            RideFeatureRule::query()->firstOrCreate(
                ['feature' => $feature, 'country_id' => $saudi->id, 'city_id' => null],
                ['enabled' => true, 'note' => 'seed — phase 1'],
            );
        }
    }

    private function reasonsAndTags(): void
    {
        $reasons = [
            ['rider', ['before_accept', 'after_accept'], false, 'غيّرت رأيي', 'I changed my mind'],
            ['rider', ['after_accept'], false, 'الكابتن متأخر', 'The captain is late'],
            ['rider', ['after_accept', 'after_arrival'], false, 'الكابتن طلب مني ألغي', 'The captain asked me to cancel'],
            ['driver', ['after_accept'], false, 'الراكب طلب مني ألغي', 'The rider asked me to cancel'],
            ['driver', ['after_accept'], false, 'عطل في العربية', 'Vehicle problem'],
            ['both', ['after_accept', 'after_arrival', 'in_progress'], true, 'مش حاسس بأمان', 'I don\'t feel safe'],
        ];
        foreach ($reasons as $i => [$actor, $stages, $safety, $ar, $en]) {
            $reason = RideCancelReason::withTrashed()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('label', $en))->first()
                ?? RideCancelReason::query()->create(['actor' => $actor, 'stages' => $stages, 'is_safety' => $safety, 'status' => true, 'sort_order' => $i + 1]);
            $this->translate($reason, ['ar' => ['label' => $ar], 'en' => ['label' => $en]]);
        }

        $tags = [
            ['driver', 'positive', 'عربية نضيفة', 'Clean car'], ['driver', 'positive', 'سواقة آمنة', 'Safe driving'],
            ['driver', 'positive', 'ذوق ومحترم', 'Polite'], ['driver', 'negative', 'اتأخر', 'Late'],
            ['driver', 'negative', 'لف بالطريق', 'Took a longer route'], ['rider', 'positive', 'في الميعاد', 'On time'],
            ['rider', 'negative', 'خلاني أستنى', 'Kept me waiting'],
        ];
        foreach ($tags as $i => [$target, $sentiment, $ar, $en]) {
            $tag = RideRatingTag::withTrashed()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('label', $en))->first()
                ?? RideRatingTag::query()->create(['target' => $target, 'sentiment' => $sentiment, 'status' => true, 'sort_order' => $i + 1]);
            $this->translate($tag, ['ar' => ['label' => $ar], 'en' => ['label' => $en]]);
        }
    }

    private function points(): void
    {
        $rules = [
            ['trip_completed', 10, null],
            ['on_time_pickup', 0, [['max_minutes_late' => 0, 'points' => 20], ['max_minutes_late' => 3, 'points' => 10]]],
            ['five_stars', 5, null],
        ];
        foreach ($rules as [$event, $points, $conditions]) {
            DriverPointsRule::withTrashed()->firstOrCreate(['country_id' => null, 'event' => $event], ['points' => $points, 'conditions' => $conditions, 'status' => true]);
        }
    }

    private function discoverCityId(int $countryId, string $arabicName): ?int
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        if (! class_exists($class)) {
            return null;
        }

        $city = $class::query()->where('country_id', $countryId)
            ->whereHas('translations', fn ($q) => $q->where('locale', 'ar')->where('name', $arabicName))
            ->first();

        return $city?->getKey();
    }

    /**
     * @param  array<string, array<string, string>>  $byLocale
     */
    private function translate(Model $model, array $byLocale): void
    {
        foreach ($byLocale as $locale => $fields) {
            $model->translations()->updateOrCreate(['locale' => $locale], $fields);
        }
    }
}
