<?php

namespace Database\Seeders\General;

use App\Enums\ServiceAudience;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategoriesSeeder extends Seeder
{
    /**
     * Internal admin catalog entries — not shown on the user app home.
     *
     * @var list<string>
     */
    private const ADMIN_ONLY_MODULES = [
        'general_services',
        'system_users',
        'admin',
        'admin_permission',
    ];

    public function run(): void
    {
        $rows = [
            ['name' => ['ar' => 'الخدمات العامة', 'en' => 'General Services'], 'module_name' => 'general_services', 'requires_provider' => false],
            ['name' => ['ar' => 'مستخدمين النظام', 'en' => 'System Users'], 'module_name' => 'system_users', 'requires_provider' => false],
            ['name' => ['ar' => 'لوحة الإدارة', 'en' => 'Admin'], 'module_name' => 'admin', 'requires_provider' => false],
            ['name' => ['ar' => 'صلاحيات الإدارة', 'en' => 'Admin Permissions'], 'module_name' => 'admin_permission', 'requires_provider' => false],
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

            $audiences = $this->audiencesForRow($row);
            $legacy = ServiceAudience::legacyFlagsFromAudiences($audiences);

            $category->fill([
                'module_name' => $row['module_name'],
                'audiences' => $audiences,
                'is_login_dashboard' => $legacy['is_login_dashboard'],
                'is_auto_assign' => false,
                'requires_provider' => $legacy['requires_provider'],
                'status' => true,
                'sort_order' => $index + 1,
            ]);
            $category->save();

            $this->syncServiceCategoryTranslations($category, $row['name']);
        }
    }

    /**
     * @param  array<string, string>  $names
     */
    protected function syncServiceCategoryTranslations(ServiceCategory $category, array $names): void
    {
        foreach ($names as $locale => $name) {
            $category->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'name' => $name,
                    'description' => $locale === 'ar'
                        ? $this->defaultDescriptionAr()
                        : $this->defaultDescriptionEn(),
                ],
            );
        }
    }

    private function defaultDescriptionAr(): string
    {
        return <<<'HTML'
<p>نقدم خدمات متنوعة تهدف إلى تلبية احتياجاتك اليومية من خلال حلول عملية ومرنة، مع الحرص على توفير تجربة سهلة ومريحة تجمع بين الجودة والسهولة والموثوقية.</p>
<h3>مميزات خدماتنا:</h3>
<ul>
<li><strong>سهولة الاستخدام:</strong> تجربة بسيطة تساعدك في الوصول إلى الخدمة المناسبة بكل سهولة.</li>
<li><strong>جودة الخدمة:</strong> نحرص على تقديم خدمات تلبي توقعاتك واحتياجاتك.</li>
<li><strong>المرونة:</strong> حلول متنوعة تناسب مختلف الاحتياجات والتفضيلات.</li>
<li><strong>توفير الوقت والجهد:</strong> الوصول إلى الخدمات بطريقة سهلة ومنظمة.</li>
<li><strong>التحسين المستمر:</strong> نعمل على تطوير خدماتنا لتقديم تجربة أفضل.</li>
<li><strong>تجربة موثوقة:</strong> نسعى إلى توفير تجربة مريحة وموثوقة في كل خطوة.</li>
</ul>
HTML;
    }

    private function defaultDescriptionEn(): string
    {
        return <<<'HTML'
<p>We offer a variety of services designed to meet your everyday needs through practical and flexible solutions. Our goal is to provide a smooth and convenient experience that combines quality, simplicity, and reliability.</p>
<h3>Our Service Features:</h3>
<ul>
<li><strong>Easy to Use:</strong> A simple experience that helps you access the right service with ease.</li>
<li><strong>Quality Service:</strong> We strive to deliver services that meet your expectations and needs.</li>
<li><strong>Flexibility:</strong> A variety of solutions to suit different needs and preferences.</li>
<li><strong>Save Time and Effort:</strong> Access services through a simple and organized process.</li>
<li><strong>Continuous Improvement:</strong> We continuously develop our services to provide a better experience.</li>
<li><strong>Reliable Experience:</strong> We aim to make every step convenient and reliable.</li>
</ul>
HTML;
    }

    /**
     * @param  array{module_name: string, requires_provider: bool}  $row
     * @return list<string>
     */
    protected function audiencesForRow(array $row): array
    {
        if (in_array($row['module_name'], self::ADMIN_ONLY_MODULES, true)) {
            return [ServiceAudience::Admin->value];
        }

        $audiences = [
            ServiceAudience::Admin->value,
            ServiceAudience::User->value,
        ];

        if ($row['requires_provider']) {
            $audiences[] = ServiceAudience::Provider->value;
        }

        if ($row['module_name'] === 'driver_without_vehicle') {
            $audiences[] = ServiceAudience::Driver->value;
        }

        return array_values(array_unique($audiences));
    }
}
