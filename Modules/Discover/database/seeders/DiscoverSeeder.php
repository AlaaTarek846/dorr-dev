<?php

namespace Modules\Discover\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\Discover\Models\DiscoverCategory;
use Modules\Discover\Models\DiscoverCity;
use Modules\Discover\Models\DiscoverSetting;

/**
 * Discover's starting catalog: the kinds of events and the main cities of the countries we serve,
 * each with its time zone. Safe to run again — it only adds what's missing; the admin edits the rest.
 */
class DiscoverSeeder extends Seeder
{
    private const CATEGORIES = [
        ['concerts', '🎵', '#8E44AD', 'Concerts', 'حفلات'],
        ['exhibitions', '🖼️', '#2E86C1', 'Exhibitions', 'معارض'],
        ['festivals', '🎪', '#E67E22', 'Festivals', 'مهرجانات'],
        ['sports', '⚽', '#27AE60', 'Sports', 'رياضة'],
        ['family', '👨‍👩‍👧', '#F1C40F', 'Family & kids', 'عائلة وأطفال'],
        ['theatre', '🎭', '#C0392B', 'Theatre & cinema', 'مسرح وسينما'],
        ['courses', '📚', '#16A085', 'Courses & workshops', 'دورات وورش'],
        ['conferences', '🎤', '#34495E', 'Conferences', 'مؤتمرات'],
        ['food', '🍽️', '#D35400', 'Food & markets', 'أكل وأسواق'],
        ['culture', '🕌', '#7F8C8D', 'Culture & heritage', 'ثقافة وتراث'],
    ];

    /** country code => [[timezone, lat, lng, en, ar], …] */
    private const CITIES = [
        'SA' => [
            ['Asia/Riyadh', 24.7136, 46.6753, 'Riyadh', 'الرياض'],
            ['Asia/Riyadh', 21.4858, 39.1925, 'Jeddah', 'جدة'],
            ['Asia/Riyadh', 26.4207, 50.0888, 'Dammam', 'الدمام'],
            ['Asia/Riyadh', 21.3891, 39.8579, 'Makkah', 'مكة المكرمة'],
            ['Asia/Riyadh', 24.5247, 39.5692, 'Madinah', 'المدينة المنورة'],
        ],
        'EG' => [
            ['Africa/Cairo', 30.0444, 31.2357, 'Cairo', 'القاهرة'],
            ['Africa/Cairo', 31.2001, 29.9187, 'Alexandria', 'الإسكندرية'],
            ['Africa/Cairo', 30.0131, 31.2089, 'Giza', 'الجيزة'],
        ],
        'AE' => [
            ['Asia/Dubai', 25.2048, 55.2708, 'Dubai', 'دبي'],
            ['Asia/Dubai', 24.4539, 54.3773, 'Abu Dhabi', 'أبوظبي'],
            ['Asia/Dubai', 25.3463, 55.4209, 'Sharjah', 'الشارقة'],
        ],
        'KW' => [['Asia/Kuwait', 29.3759, 47.9774, 'Kuwait City', 'مدينة الكويت']],
        'QA' => [['Asia/Qatar', 25.2854, 51.5310, 'Doha', 'الدوحة']],
        'BH' => [['Asia/Bahrain', 26.2285, 50.5860, 'Manama', 'المنامة']],
        'OM' => [['Asia/Muscat', 23.5880, 58.3829, 'Muscat', 'مسقط']],
        'JO' => [['Asia/Amman', 31.9454, 35.9284, 'Amman', 'عمّان']],
    ];

    public function run(): void
    {
        DiscoverSetting::query()->firstOrCreate([]);

        foreach (self::CATEGORIES as $i => [$key, $emoji, $color, $en, $ar]) {
            $category = DiscoverCategory::query()->firstOrCreate(['key' => $key], ['emoji' => $emoji, 'color' => $color, 'status' => true, 'sort_order' => $i + 1]);
            $category->translations()->firstOrCreate(['locale' => 'en'], ['name' => $en]);
            $category->translations()->firstOrCreate(['locale' => 'ar'], ['name' => $ar]);
        }

        foreach (self::CITIES as $code => $cities) {
            $country = Country::query()->where('code', $code)->first();
            if ($country === null) {
                continue;
            }
            foreach ($cities as $i => [$zone, $lat, $lng, $en, $ar]) {
                $exists = DiscoverCity::query()->where('country_id', $country->id)
                    ->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $en))->exists();
                if ($exists) {
                    continue;
                }
                $city = DiscoverCity::query()->create(['country_id' => $country->id, 'timezone' => $zone, 'lat' => $lat, 'lng' => $lng, 'status' => true, 'sort_order' => $i + 1]);
                $city->translations()->create(['locale' => 'en', 'name' => $en]);
                $city->translations()->create(['locale' => 'ar', 'name' => $ar]);
            }
        }
    }
}
