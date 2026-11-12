<?php

namespace Modules\AI\Database\Seeders;

use App\Models\Country;
use App\Repositories\General\LanguageRepository;
use Illuminate\Database\Seeder;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteOffer;

/**
 * Starter data for the AI website builder: one standalone "build a site" offer, a monthly and a
 * yearly hosting plan, example prices for Saudi Arabia and Egypt, and builder limits on the paid
 * AI plans, plus the source-download switch and three edit packs (more AI edits for an existing site once its own allowance is used
 * up, paid from the wallet). Safe to re-run: rows are found by their unique code (trash included), prices are only
 * created when that country has none yet, the Arabic / English text is only written for a language that has none
 * yet, and plan limits are only filled while they are still 0 - so anything the admin edited afterwards is kept.
 * All numbers are examples; change them from the admin screens.
 */
class AiSiteSeeder extends Seeder
{
    /** country ISO code => [offer price, monthly hosting, yearly hosting] */
    private const PRICES = [
        'SA' => [199, 29, 290],
        'EG' => [900, 130, 1300],
    ];

    /** Customer-facing text per language: code => [locale => [name, description]] */
    private const TEXT = [
        'site_single' => [
            'ar' => ['إنشاء موقع بالذكاء الاصطناعي', 'موقع كامل جاهز من وصفك، مع إمكانية التعديل بالتعليمات وتحميل الملفات.'],
            'en' => ['AI website', 'A complete website built from your description, editable with instructions and downloadable.'],
        ],
        'hosting_monthly' => [
            'ar' => ['استضافة شهرية', 'موقعك على عنوانك الخاص، يتجدد كل شهر.'],
            'en' => ['Monthly hosting', 'Your site on its own address, renewed every month.'],
        ],
        'hosting_yearly' => [
            'ar' => ['استضافة سنوية', 'موقعك على عنوانك الخاص، يتجدد كل سنة بسعر أوفر.'],
            'en' => ['Yearly hosting', 'Your site on its own address, renewed every year at a better price.'],
        ],
        'source_download' => [
            'ar' => ['تحميل سورس الموقع', 'تحميل ملفات موقعك كاملة في ملف ZIP.'],
            'en' => ['Source download', 'Download all of your site\'s files as a ZIP.'],
        ],
        'edit_pack_5' => [
            'ar' => ['باقة 5 تعديلات', 'خمس تعديلات إضافية بالذكاء الاصطناعي على موقعك.'],
            'en' => ['5 edits pack', 'Five more AI edits for your site.'],
        ],
        'edit_pack_15' => [
            'ar' => ['باقة 15 تعديل', 'خمسة عشر تعديلاً إضافياً بسعر أوفر.'],
            'en' => ['15 edits pack', 'Fifteen more AI edits at a better price.'],
        ],
        'edit_pack_40' => [
            'ar' => ['باقة 40 تعديل', 'أربعون تعديلاً إضافياً لأكثر المواقع نشاطاً، بأوفر سعر للتعديل.'],
            'en' => ['40 edits pack', 'Forty more AI edits for the busiest sites, at the best price per edit.'],
        ],
    ];

    /** code => edits included */
    private const EDIT_PACKS = [
        'edit_pack_5' => 5,
        'edit_pack_15' => 15,
        'edit_pack_40' => 40,
    ];

    /** country ISO code => edit pack prices in the same order as EDIT_PACKS */
    private const PACK_PRICES = [
        'SA' => [39, 99, 229],
        'EG' => [180, 480, 1100],
    ];

    public function run(): void
    {
        $this->call(AiSiteRequestStatusSeeder::class);

        $offer = AiSiteOffer::withTrashed()->updateOrCreate(
            ['code' => 'site_single'],
            ['generations_included' => 10, 'status' => true, 'sort_order' => 1],
        );
        $this->text($offer);

        $monthly = AiSiteHostingPlan::withTrashed()->updateOrCreate(
            ['code' => 'hosting_monthly'],
            ['period' => 'monthly', 'status' => true, 'sort_order' => 1],
        );
        $this->text($monthly);

        $yearly = AiSiteHostingPlan::withTrashed()->updateOrCreate(
            ['code' => 'hosting_yearly'],
            ['period' => 'yearly', 'status' => true, 'sort_order' => 2],
        );
        $this->text($yearly);

        // Source download (ZIP): on and free, as it always was; the admin can close it or price it per country.
        $download = AiSiteOffer::withTrashed()->firstOrCreate(
            ['kind' => AiSiteOffer::KIND_SOURCE_DOWNLOAD],
            ['code' => 'source_download', 'generations_included' => 0, 'status' => true, 'is_free' => true, 'sort_order' => 0],
        );
        $this->text($download, 'source_download');

        $packs = [];
        $order = 10;

        foreach (self::EDIT_PACKS as $code => $edits) {
            // firstOrCreate: a pack the admin re-priced or re-ordered is left as they set it.
            $pack = AiSiteOffer::withTrashed()->firstOrCreate(
                ['code' => $code],
                ['kind' => AiSiteOffer::KIND_EDIT_PACK, 'generations_included' => $edits, 'status' => true, 'sort_order' => $order++],
            );
            $this->text($pack);
            $packs[] = $pack;
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

    /**
     * Writes the Arabic / English text into every stored language that has none yet (a language other than ar / en
     * gets the English text until the admin translates it). Text the admin already wrote is left alone.
     */
    private function text(AiSiteOffer|AiSiteHostingPlan $model, ?string $key = null): void
    {
        $text = self::TEXT[$key ?? $model->code];
        $locales = LanguageRepository::storableLocaleCodes() ?: ['ar', 'en'];

        foreach ($locales as $locale) {
            [$name, $description] = $text[$locale] ?? $text['en'];

            $model->translations()->firstOrCreate(['locale' => $locale], ['name' => $name, 'description' => $description]);
        }

        $model->unsetRelation('translations');
    }

    private function price($relation, Country $country, int $price): void
    {
        $relation->firstOrCreate(
            ['country_id' => $country->id],
            ['currency_id' => $country->currency_id, 'price' => $price],
        );
    }
}
