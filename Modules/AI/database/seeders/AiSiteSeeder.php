<?php

namespace Modules\AI\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteOffer;

/**
 * Starter data for the AI website builder: one standalone "build a site" offer, a monthly and a
 * yearly hosting plan, example prices for Saudi Arabia and Egypt, and builder limits on the paid
 * AI plans, plus the source-download switch and three edit packs (more AI edits for an existing site once its own allowance is used
 * up, paid from the wallet). Safe to re-run: rows are found by their unique code, prices are only created when that
 * country has none yet, and plan limits are only filled while they are still 0 - so anything the
 * admin edited afterwards is kept. All numbers are examples; change them from the admin screens.
 */
class AiSiteSeeder extends Seeder
{
    /** country ISO code => [offer price, monthly hosting, yearly hosting] */
    private const PRICES = [
        'SA' => [199, 29, 290],
        'EG' => [900, 130, 1300],
    ];

    /** code => [name, edits included, description] */
    private const EDIT_PACKS = [
        'edit_pack_5' => ['باقة 5 تعديلات', 5, 'خمس تعديلات إضافية بالذكاء الاصطناعي على موقعك.'],
        'edit_pack_15' => ['باقة 15 تعديل', 15, 'خمسة عشر تعديلاً إضافياً بسعر أوفر.'],
        'edit_pack_40' => ['باقة 40 تعديل', 40, 'أربعون تعديلاً إضافياً لأكثر المواقع نشاطاً، بأوفر سعر للتعديل.'],
    ];

    /** country ISO code => edit pack prices in the same order as EDIT_PACKS */
    private const PACK_PRICES = [
        'SA' => [39, 99, 229],
        'EG' => [180, 480, 1100],
    ];

    public function run(): void
    {
        $this->call(AiSiteRequestStatusSeeder::class);

        $offer = AiSiteOffer::query()->updateOrCreate(
            ['code' => 'site_single'],
            [
                'name' => 'إنشاء موقع بالذكاء الاصطناعي',
                'description' => 'موقع كامل جاهز من وصفك، مع إمكانية التعديل بالتعليمات وتحميل الملفات.',
                'generations_included' => 10,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $monthly = AiSiteHostingPlan::query()->updateOrCreate(
            ['code' => 'hosting_monthly'],
            ['name' => 'استضافة شهرية', 'period' => 'monthly', 'description' => 'موقعك على عنوانك الخاص، يتجدد كل شهر.', 'is_active' => true, 'sort_order' => 1],
        );

        $yearly = AiSiteHostingPlan::query()->updateOrCreate(
            ['code' => 'hosting_yearly'],
            ['name' => 'استضافة سنوية', 'period' => 'yearly', 'description' => 'موقعك على عنوانك الخاص، يتجدد كل سنة بسعر أوفر.', 'is_active' => true, 'sort_order' => 2],
        );

        // Source download (ZIP): on and free, as it always was; the admin can close it or price it per country.
        AiSiteOffer::query()->firstOrCreate(
            ['kind' => AiSiteOffer::KIND_SOURCE_DOWNLOAD],
            ['name' => 'تحميل سورس الموقع', 'code' => 'source_download', 'description' => 'تحميل ملفات موقعك كاملة في ملف ZIP.', 'generations_included' => 0, 'is_active' => true, 'is_free' => true, 'sort_order' => 0],
        );

        $packs = [];
        $order = 10;

        foreach (self::EDIT_PACKS as $code => [$name, $edits, $description]) {
            // firstOrCreate: a pack the admin renamed or re-priced is left as they set it.
            $packs[] = AiSiteOffer::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'kind' => AiSiteOffer::KIND_EDIT_PACK, 'description' => $description, 'generations_included' => $edits, 'is_active' => true, 'sort_order' => $order++],
            );
        }

        foreach (self::PRICES as $iso => [$offerPrice, $monthlyPrice, $yearlyPrice]) {
            $country = Country::query()->where('code', $iso)->first();

            if ($country === null || $country->currency_id === null) {
                continue;
            }

            $this->price($offer->prices(), $country, $offerPrice);
            $this->price($monthly->prices(), $country, $monthlyPrice);
            $this->price($yearly->prices(), $country, $yearlyPrice);

            foreach ($packs as $index => $pack) {
                $this->price($pack->prices(), $country, self::PACK_PRICES[$iso][$index]);
            }
        }

        // Builder limits on the paid plans (projects kept / generations per day).
        foreach (['pro' => [3, 5], 'business' => [10, 20]] as $code => [$projects, $daily]) {
            AiPlan::query()->where('code', $code)->where('site_projects_limit', 0)->update([
                'site_projects_limit' => $projects,
                'site_daily_generations' => $daily,
            ]);
        }
    }

    private function price($relation, Country $country, int $price): void
    {
        $relation->firstOrCreate(
            ['country_id' => $country->id],
            ['currency_id' => $country->currency_id, 'price' => $price],
        );
    }
}
