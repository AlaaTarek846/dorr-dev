<?php

namespace Database\Seeders\General;

use App\Models\ServiceCategory;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

class ServiceCategoriesSeeder extends Seeder
{
    use SyncsSeedTranslations;

    public function run(): void
    {
        $rows = [
            ['name' => ['ar' => 'الدردشة', 'en' => 'Chat'], 'module_name' => 'chat', 'requires_provider' => false],
            ['name' => ['ar' => 'توصيل الركاب', 'en' => 'Passenger Ride'], 'module_name' => 'passenger_ride', 'requires_provider' => true],
            ['name' => ['ar' => 'تأجير السيارات', 'en' => 'Car Rental'], 'module_name' => 'car_rental', 'requires_provider' => true],
            ['name' => ['ar' => 'تعليم القيادة', 'en' => 'Driving Lessons'], 'module_name' => 'driving_lessons', 'requires_provider' => true],
            ['name' => ['ar' => 'سائق بدون مركبة', 'en' => 'Driver Without Vehicle'], 'module_name' => 'driver_without_vehicle', 'requires_provider' => true],
            ['name' => ['ar' => 'المطاعم', 'en' => 'Restaurants'], 'module_name' => 'restaurants', 'requires_provider' => true],
            ['name' => ['ar' => 'الطرود', 'en' => 'Parcels'], 'module_name' => 'parcels', 'requires_provider' => true],
            ['name' => ['ar' => 'نقل العفش', 'en' => 'Moving'], 'module_name' => 'moving', 'requires_provider' => true],
            ['name' => ['ar' => 'المياه والغاز', 'en' => 'Water & Gas'], 'module_name' => 'water_gas', 'requires_provider' => true],
            ['name' => ['ar' => 'الوقود', 'en' => 'Fuel'], 'module_name' => 'fuel', 'requires_provider' => true],
            ['name' => ['ar' => 'الميكانيكي', 'en' => 'Mechanic'], 'module_name' => 'mechanic', 'requires_provider' => true],
            ['name' => ['ar' => 'الكهربائي', 'en' => 'Electrician'], 'module_name' => 'electrician', 'requires_provider' => true],
            ['name' => ['ar' => 'غسيل السيارات', 'en' => 'Car Wash'], 'module_name' => 'car_wash', 'requires_provider' => true],
            ['name' => ['ar' => 'التنظيف', 'en' => 'Cleaning'], 'module_name' => 'cleaning', 'requires_provider' => true],
            ['name' => ['ar' => 'المطابع', 'en' => 'Printing'], 'module_name' => 'printing', 'requires_provider' => true],
            ['name' => ['ar' => 'المصممين', 'en' => 'Designers'], 'module_name' => 'designers', 'requires_provider' => true],
            ['name' => ['ar' => 'المتاجر', 'en' => 'Stores'], 'module_name' => 'stores', 'requires_provider' => true],
            ['name' => ['ar' => 'النظام المحاسبي', 'en' => 'Accounting System'], 'module_name' => 'accounting_system', 'requires_provider' => false],
            ['name' => ['ar' => 'المستلزمات الطبية', 'en' => 'Medical Supplies'], 'module_name' => 'medical_supplies', 'requires_provider' => true],
            ['name' => ['ar' => 'الفنادق والطيران', 'en' => 'Hotels & Flights'], 'module_name' => 'hotels_flights', 'requires_provider' => true],
            ['name' => ['ar' => 'رحلات الحرمين', 'en' => 'Umrah & Hajj Trips'], 'module_name' => 'umrah_hajj_trips', 'requires_provider' => true],
            ['name' => ['ar' => 'الشقق المفروشة', 'en' => 'Furnished Apartments'], 'module_name' => 'furnished_apartments', 'requires_provider' => true],
            ['name' => ['ar' => 'الاستراحات والشاليهات', 'en' => 'Chalets & Resorts'], 'module_name' => 'chalets_resorts', 'requires_provider' => true],
            ['name' => ['ar' => 'الصالونات', 'en' => 'Salons'], 'module_name' => 'salons', 'requires_provider' => true],
            ['name' => ['ar' => 'الوجبات المنزلية', 'en' => 'Home Meals'], 'module_name' => 'home_meals', 'requires_provider' => true],
            ['name' => ['ar' => 'الشيف', 'en' => 'Private Chef'], 'module_name' => 'private_chef', 'requires_provider' => true],
            ['name' => ['ar' => 'مباشرات المناسبات', 'en' => 'Event Catering'], 'module_name' => 'event_catering', 'requires_provider' => true],
            ['name' => ['ar' => 'الملاعب', 'en' => 'Sports Venues'], 'module_name' => 'sports_venues', 'requires_provider' => true],
            ['name' => ['ar' => 'الفعاليات', 'en' => 'Events'], 'module_name' => 'events', 'requires_provider' => true],
            ['name' => ['ar' => 'العمل الحر', 'en' => 'Freelance'], 'module_name' => 'freelance', 'requires_provider' => true],
            ['name' => ['ar' => 'المساعد الذكي', 'en' => 'AI Assistant'], 'module_name' => 'ai_assistant', 'requires_provider' => false],
        ];

        foreach ($rows as $index => $row) {
            $category = ServiceCategory::query()
                ->where('sort_order', $index + 1)
                ->first() ?? new ServiceCategory;

            $category->fill([
                'module_name' => $row['module_name'],
                'is_login_dashboard' => true,
                'is_auto_assign' => false,
                'requires_provider' => $row['requires_provider'],
                'status' => true,
                'sort_order' => $index + 1,
            ]);
            $category->save();

            $this->syncTranslations($category, $row['name']);
        }
    }
}
