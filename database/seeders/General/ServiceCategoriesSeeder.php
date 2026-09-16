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
            ['name' => ['ar' => 'الدردشة', 'en' => 'Chat'], 'requires_provider' => false],
            ['name' => ['ar' => 'توصيل الركاب', 'en' => 'Passenger Ride'], 'requires_provider' => true],
            ['name' => ['ar' => 'تأجير السيارات', 'en' => 'Car Rental'], 'requires_provider' => true],
            ['name' => ['ar' => 'تعليم القيادة', 'en' => 'Driving Lessons'], 'requires_provider' => true],
            ['name' => ['ar' => 'سائق بدون مركبة', 'en' => 'Driver Without Vehicle'], 'requires_provider' => true],
            ['name' => ['ar' => 'المطاعم', 'en' => 'Restaurants'], 'requires_provider' => true],
            ['name' => ['ar' => 'الطرود', 'en' => 'Parcels'], 'requires_provider' => true],
            ['name' => ['ar' => 'نقل العفش', 'en' => 'Moving'], 'requires_provider' => true],
            ['name' => ['ar' => 'المياه والغاز', 'en' => 'Water & Gas'], 'requires_provider' => true],
            ['name' => ['ar' => 'الوقود', 'en' => 'Fuel'], 'requires_provider' => true],
            ['name' => ['ar' => 'الميكانيكي', 'en' => 'Mechanic'], 'requires_provider' => true],
            ['name' => ['ar' => 'الكهربائي', 'en' => 'Electrician'], 'requires_provider' => true],
            ['name' => ['ar' => 'غسيل السيارات', 'en' => 'Car Wash'], 'requires_provider' => true],
            ['name' => ['ar' => 'التنظيف', 'en' => 'Cleaning'], 'requires_provider' => true],
            ['name' => ['ar' => 'المطابع', 'en' => 'Printing'], 'requires_provider' => true],
            ['name' => ['ar' => 'المصممين', 'en' => 'Designers'], 'requires_provider' => true],
            ['name' => ['ar' => 'المتاجر', 'en' => 'Stores'], 'requires_provider' => true],
            ['name' => ['ar' => 'النظام المحاسبي', 'en' => 'Accounting System'], 'requires_provider' => false],
            ['name' => ['ar' => 'المستلزمات الطبية', 'en' => 'Medical Supplies'], 'requires_provider' => true],
            ['name' => ['ar' => 'الفنادق والطيران', 'en' => 'Hotels & Flights'], 'requires_provider' => true],
            ['name' => ['ar' => 'رحلات الحرمين', 'en' => 'Umrah & Hajj Trips'], 'requires_provider' => true],
            ['name' => ['ar' => 'الشقق المفروشة', 'en' => 'Furnished Apartments'], 'requires_provider' => true],
            ['name' => ['ar' => 'الاستراحات والشاليهات', 'en' => 'Chalets & Resorts'], 'requires_provider' => true],
            ['name' => ['ar' => 'الصالونات', 'en' => 'Salons'], 'requires_provider' => true],
            ['name' => ['ar' => 'الوجبات المنزلية', 'en' => 'Home Meals'], 'requires_provider' => true],
            ['name' => ['ar' => 'الشيف', 'en' => 'Private Chef'], 'requires_provider' => true],
            ['name' => ['ar' => 'مباشرات المناسبات', 'en' => 'Event Catering'], 'requires_provider' => true],
            ['name' => ['ar' => 'الملاعب', 'en' => 'Sports Venues'], 'requires_provider' => true],
            ['name' => ['ar' => 'الفعاليات', 'en' => 'Events'], 'requires_provider' => true],
            ['name' => ['ar' => 'العمل الحر', 'en' => 'Freelance'], 'requires_provider' => true],
            ['name' => ['ar' => 'المساعد الذكي', 'en' => 'AI Assistant'], 'requires_provider' => false],
        ];

        foreach ($rows as $index => $row) {
            $category = ServiceCategory::query()
                ->where('sort_order', $index + 1)
                ->first() ?? new ServiceCategory;

            $category->fill([
                'requires_provider' => $row['requires_provider'],
                'status' => true,
                'sort_order' => $index + 1,
            ]);
            $category->save();

            $this->syncTranslations($category, $row['name']);
        }
    }
}
