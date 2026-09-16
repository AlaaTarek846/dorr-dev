<?php

namespace Database\Seeders\General;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name_ar' => 'الدردشة', 'slug' => 'chat', 'department' => 'Communication', 'base_model' => 'chat', 'requires_provider' => false, 'provider_type_label' => null],
            ['name_ar' => 'توصيل الركاب', 'slug' => 'passenger-ride', 'department' => 'Mobility', 'base_model' => 'trip', 'requires_provider' => true, 'provider_type_label' => 'Driver'],
            ['name_ar' => 'تأجير السيارات', 'slug' => 'car-rental', 'department' => 'Mobility', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Car Rental Provider'],
            ['name_ar' => 'تعليم القيادة', 'slug' => 'driving-lessons', 'department' => 'Education / Mobility', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Driving Instructor / Center'],
            ['name_ar' => 'سائق بدون مركبة', 'slug' => 'driver-without-vehicle', 'department' => 'Mobility', 'base_model' => 'trip', 'requires_provider' => true, 'provider_type_label' => 'Driver'],
            ['name_ar' => 'المطاعم', 'slug' => 'restaurants', 'department' => 'Food', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Restaurant'],
            ['name_ar' => 'الطرود', 'slug' => 'parcels', 'department' => 'Logistics', 'base_model' => 'delivery', 'requires_provider' => true, 'provider_type_label' => 'Driver / Courier'],
            ['name_ar' => 'نقل العفش', 'slug' => 'moving', 'department' => 'Logistics', 'base_model' => 'service_request', 'requires_provider' => true, 'provider_type_label' => 'Moving Provider / Driver'],
            ['name_ar' => 'المياه والغاز', 'slug' => 'water-gas', 'department' => 'Home Services', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Supplier / Provider'],
            ['name_ar' => 'الوقود', 'slug' => 'fuel', 'department' => 'Automotive / Delivery', 'base_model' => 'delivery', 'requires_provider' => true, 'provider_type_label' => 'Fuel Provider / Driver'],
            ['name_ar' => 'الميكانيكي', 'slug' => 'mechanic', 'department' => 'Automotive Services', 'base_model' => 'on_demand', 'requires_provider' => true, 'provider_type_label' => 'Mechanic'],
            ['name_ar' => 'الكهربائي', 'slug' => 'electrician', 'department' => 'Home / Automotive Services', 'base_model' => 'on_demand', 'requires_provider' => true, 'provider_type_label' => 'Electrician'],
            ['name_ar' => 'غسيل السيارات', 'slug' => 'car-wash', 'department' => 'Automotive Services', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Car Wash Provider'],
            ['name_ar' => 'التنظيف', 'slug' => 'cleaning', 'department' => 'Home Services', 'base_model' => 'on_demand', 'requires_provider' => true, 'provider_type_label' => 'Cleaning Provider'],
            ['name_ar' => 'المطابع', 'slug' => 'printing', 'department' => 'Business Services', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Printing Provider'],
            ['name_ar' => 'المصممين', 'slug' => 'designers', 'department' => 'Creative Services', 'base_model' => 'project', 'requires_provider' => true, 'provider_type_label' => 'Designer'],
            ['name_ar' => 'المتاجر', 'slug' => 'stores', 'department' => 'Commerce', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Store / Merchant'],
            ['name_ar' => 'النظام المحاسبي', 'slug' => 'accounting', 'department' => 'Finance', 'base_model' => null, 'requires_provider' => false, 'provider_type_label' => null],
            ['name_ar' => 'المستلزمات الطبية', 'slug' => 'medical-supplies', 'department' => 'Commerce / Medical', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Medical Supplier / Store'],
            ['name_ar' => 'الفنادق والطيران', 'slug' => 'hotels-flights', 'department' => 'Travel & Hospitality', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Hotel / Travel Provider'],
            ['name_ar' => 'رحلات الحرمين', 'slug' => 'umrah-hajj-trips', 'department' => 'Travel', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Trip Operator'],
            ['name_ar' => 'الشقق المفروشة', 'slug' => 'furnished-apartments', 'department' => 'Accommodation', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Property Owner / Manager'],
            ['name_ar' => 'الاستراحات والشاليهات', 'slug' => 'chalets-resorts', 'department' => 'Accommodation', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Property Owner / Manager'],
            ['name_ar' => 'الصالونات', 'slug' => 'salons', 'department' => 'Beauty', 'base_model' => 'appointment', 'requires_provider' => true, 'provider_type_label' => 'Salon'],
            ['name_ar' => 'الوجبات المنزلية', 'slug' => 'home-meals', 'department' => 'Food', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Home Food Provider'],
            ['name_ar' => 'الشيف', 'slug' => 'private-chef', 'department' => 'Food / Catering', 'base_model' => 'order', 'requires_provider' => true, 'provider_type_label' => 'Chef'],
            ['name_ar' => 'مباشرات المناسبات', 'slug' => 'event-catering', 'department' => 'Events / Catering', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Event Service Provider'],
            ['name_ar' => 'الملاعب', 'slug' => 'sports-venues', 'department' => 'Sports', 'base_model' => 'booking', 'requires_provider' => true, 'provider_type_label' => 'Sports Venue Owner'],
            ['name_ar' => 'الفعاليات', 'slug' => 'events', 'department' => 'Events', 'base_model' => 'ticket', 'requires_provider' => true, 'provider_type_label' => 'Event Organizer'],
            ['name_ar' => 'العمل الحر', 'slug' => 'freelance', 'department' => 'Freelance', 'base_model' => 'project', 'requires_provider' => true, 'provider_type_label' => 'Freelancer'],
            ['name_ar' => 'المساعد الذكي', 'slug' => 'ai-assistant', 'department' => 'AI', 'base_model' => null, 'requires_provider' => false, 'provider_type_label' => null],
        ];

        foreach ($rows as $index => $row) {
            ServiceCategory::updateOrCreate(
                ['slug' => $row['slug']],
                $row + ['status' => true, 'sort_order' => $index + 1],
            );
        }
    }
}
