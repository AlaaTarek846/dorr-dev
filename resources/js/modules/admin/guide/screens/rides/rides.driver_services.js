/**
 * Guide: captain services (admin.rides.driver_services).
 * Source of truth checked: views/rides/services/index.vue, Modules/Rides/routes/admin.php (driver-services, driver-service-rules),
 * Modules/Rides/app/Http/Controllers/Admin/DriverServiceAdminController.php, Modules/Rides/app/Services/Drivers/DriverServices.php,
 * Modules/Rides/database/migrations/2026_11_06_100000_create_driver_services.php, AdminPermissionSeeder (driver-services).
 */
export default {
    route: 'admin.rides.driver_services',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا بتحدد لكل دولة أنهي نوع عربية يشتغل في أنهي خدمة (توصيل، بالساعة، مجدول، مطار، باص، بحري...)، وبتدير الخدمات نفسها: اسمها وأيقونتها ولونها وترتيبها.',
        audience: 'مدير تشغيل التوصيل. الفتح محتاج "خدمات الكباتن: عرض"، والحفظ "تعديل"، والإضافة "إنشاء"، والحذف "حذف".',
        purpose: [
            'تفتح أو تقفل خدمة لنوع عربية معيّن في دولة معيّنة (مثلًا الباص يوصّل ركاب في مصر، ورحلات باص بس في السعودية).',
            'الكابتن بيشوف في تطبيقه الخدمات المفتوحة لعربيته بس، ويشغّل منها اللي هو عايزه.',
            'تضيف خدمة جديدة أو تعلّمها "قريبًا" علشان تظهر من غير ما حد يقدر يشغّلها.',
        ],
        sections: [
            {
                title: 'تبويب "المركبات والخدمات حسب الدولة"',
                items: [
                    { name: 'الدولة', desc: 'الدول المفعّلة بس. أول دولة بتتختار لوحدها.' },
                    { name: 'شارة "الإعدادات الافتراضية"', desc: 'معناها إن الدولة دي عمرها ما اتحفظت، والجدول بيعرض (والنظام بيشتغل بـ) القيم الافتراضية: العربية الملاكي توصيل/ساعة/مجدول/مطار/سواق/تعليم، الموتوسيكل توصيل/ساعة/مجدول، الميني باص توصيل/مجدول/باص، الباص باص، القارب واليخت بحري، ومن غير عربية سواق/تعليم.' },
                    { name: 'الجدول', desc: 'صف لكل خدمة وعمود لكل نوع (سيارة، دراجة نارية، ميني باص، باص، قارب، يخت، دون مركبة). كل خانة مفتاح تشغيل.' },
                    { name: 'حفظ', desc: 'بيحفظ الجدول كله للدولة مرة واحدة، والتغيير بيسري فورًا على توزيع الطلبات.' },
                ],
            },
            {
                title: 'تبويب "الخدمات"',
                items: [
                    { name: 'الخدمة', desc: 'الاسم بلغة اللوحة والأيقونة واللون. الصف الباهت = خدمة متوقفة.' },
                    { name: 'المفتاح', desc: 'كود ثابت (حروف إنجليزي صغيرة و _ من 2 لـ 32). التطبيقات والكود بيعتمدوا عليه، فبيتكتب مرة واحدة ومبيتغيرش.' },
                    { name: 'الاسم (ع / EN)', desc: 'إجباري باللغتين، لحد 60 حرف.' },
                    { name: 'الوصف (ع / EN)', desc: 'اختياري، لحد 160 حرف.' },
                    { name: 'الأيقونة واللون', desc: 'الأيقونة من قايمة ثابتة التطبيق بيرسمها، واللون بصيغة #RRGGBB.' },
                    { name: 'الترتيب', desc: 'من 0 لـ 999، ترتيب الظهور في الجدول والتطبيق.' },
                    { name: 'قريبًا', desc: 'الخدمة بتظهر، بس محدش يقدر يشغّلها لسه.' },
                    { name: 'مفعّلة', desc: 'لو اتقفلت الخدمة بتبقى باهتة.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'فتح خدمة الباص لركاب في دولة',
                steps: [
                    'افتح تبويب المركبات والخدمات واختار الدولة.',
                    'في صف الخدمة المطلوبة شغّل خانة "باص".',
                    'دوس حفظ. الشارة الصفرا بتختفي لأن الدولة بقى ليها إعداد محفوظ.',
                ],
            },
            {
                title: 'إضافة خدمة جديدة',
                steps: [
                    'تبويب الخدمات ← إضافة.',
                    'اكتب المفتاح والاسم باللغتين، واختار الأيقونة واللون.',
                    'لو لسه مش جاهزة علّم "قريبًا"، ودوس حفظ.',
                    'ارجع لتبويب الدول وافتحها للأنواع المناسبة في كل دولة.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق الكابتن: قايمة الخدمات اللي يقدر يشغّلها.' },
            { text: 'توزيع الطلبات في الدولة (الرحلات، بالساعة، "معايا"، المجدول، طابور المطار، رحلات الباص، البحري، السواق والتعليم) بيسأل الجدول ده.' },
            { route: 'admin.rides.categories', text: 'إعدادات كل فئة رحلة بتحدد أنواع العربيات اللي تقبلها جوه خدمة التوصيل.' },
        ],
        warnings: [
            { level: 'danger', text: 'حذف خدمة بيمسح كمان قواعدها في كل الدول واختيارات الكباتن ليها (حذف نهائي، مفيش سلة محذوفات). الأفضل تقفلها بدل ما تحذفها.' },
            { level: 'warning', text: 'أول حفظ لدولة بيثبّت الجدول كله ليها، وبعد كده القيم الافتراضية مش بتسري عليها تاني.' },
            { level: 'info', text: 'كل إضافة وتعديل وحذف وحفظ جدول بيتسجل في سجل المراجعة.' },
        ],
    },

    en: {
        summary: 'Decide, per country, which kind of vehicle works in which service (rides, hourly, scheduled, airport, bus, marine…), and manage the services themselves: name, icon, colour and order.',
        audience: 'The rides operations manager. Opening needs "Captain services: view"; saving needs update, adding create, deleting delete.',
        purpose: [
            'Open or close a service for one kind of vehicle in one country (a bus carries riders in Egypt, only runs bus trips in Saudi Arabia).',
            'Captains see only the services open to their vehicle, and switch on the ones they want.',
            'Add a service, or mark it "coming soon" so it shows but nobody can switch it on yet.',
        ],
        sections: [
            {
                title: '"Vehicles and services per country" tab',
                items: [
                    { name: 'Country', desc: 'Active countries only. The first one is picked by itself.' },
                    { name: '"Defaults" badge', desc: 'This country was never saved: the table shows (and dispatch uses) the defaults — car: rides/hourly/scheduled/airport/hire/lessons, motorcycle: rides/hourly/scheduled, minibus: rides/scheduled/bus, bus: bus, boat and yacht: marine, no vehicle: hire/lessons.' },
                    { name: 'Table', desc: 'A row per service, a column per kind (car, motorcycle, minibus, bus, boat, yacht, no vehicle). Each cell is a switch.' },
                    { name: 'Save', desc: 'Saves the whole table for the country at once; dispatch uses it immediately.' },
                ],
            },
            {
                title: '"Services" tab',
                items: [
                    { name: 'Service', desc: 'Name in the panel language, icon and colour. A faded row = inactive service.' },
                    { name: 'Key', desc: 'A fixed code (lowercase letters and _, 2–32). The apps and the code rely on it, so it is set once and never changes.' },
                    { name: 'Name (AR / EN)', desc: 'Required in both languages, up to 60 characters.' },
                    { name: 'Description (AR / EN)', desc: 'Optional, up to 160 characters.' },
                    { name: 'Icon and colour', desc: 'The icon comes from a fixed list the app draws; the colour is #RRGGBB.' },
                    { name: 'Order', desc: '0–999, the order in the table and the app.' },
                    { name: 'Coming soon', desc: 'Shown, but nobody can switch it on yet.' },
                    { name: 'Active', desc: 'An inactive service is shown faded.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Let buses carry riders in a country',
                steps: [
                    'Open the per-country tab and pick the country.',
                    'In the service row, switch on the "Bus" cell.',
                    'Save. The yellow badge goes away: the country now has its own saved table.',
                ],
            },
            {
                title: 'Add a service',
                steps: [
                    'Services tab → Add.',
                    'Enter the key, the name in both languages, pick the icon and colour.',
                    'Tick "Coming soon" if it is not ready, then save.',
                    'Go back to the per-country tab and open it for the right kinds in each country.',
                ],
            },
        ],
        affects: [
            { text: 'Captain app: the list of services a captain can switch on.' },
            { text: 'Dispatch in the country (rides, hourly hire, With Me, scheduled rides, the airport queue, bus trips, marine, driver on demand and lessons) asks this table.' },
            { route: 'admin.rides.categories', text: 'Each ride category\'s settings decide which kinds of vehicle it accepts inside the rides service.' },
        ],
        warnings: [
            { level: 'danger', text: 'Deleting a service also deletes its rules in every country and the captains\' choices for it (hard delete, no trash). Prefer switching it off.' },
            { level: 'warning', text: 'A country\'s first save pins the whole table for it; the defaults stop applying there.' },
            { level: 'info', text: 'Every add, edit, delete and table save is written to the audit log.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/rides/services/index.vue',
            'Modules/Rides/app/Http/Controllers/Admin/DriverServiceAdminController.php',
            'Modules/Rides/app/Services/Drivers/DriverServices.php (KINDS, DEFAULTS, cache rides:driver-services)',
            'Modules/Rides/app/Models/DriverService.php, DriverServiceRule.php',
        ],
        api: [
            'GET    /api/admin/v1/driver-services            (services, kinds, icons, countries)',
            'POST   /api/admin/v1/driver-services · PUT|DELETE …/driver-services/{id}',
            'GET    /api/admin/v1/driver-service-rules?country_id=   (matrix, saved)',
            'PUT    /api/admin/v1/driver-service-rules       {country_id, matrix: {service_key: {kind: bool}}}',
        ],
        tables: ['driver_services (key, name json, description json, icon, color, sort_order, coming_soon, status)', 'driver_service_rules (driver_service_id, country_id, vehicle_kind, enabled)', 'driver_service_choices (driver_id, driver_service_id, enabled)'],
        permissions: ['driver-services.view (index, rules)', 'driver-services.create (store)', 'driver-services.update (update, saveRules)', 'driver-services.delete (destroy)'],
        decisions: [
            {
                ar: 'الشاشة لسه مش زي الدول: الاسم والوصف متخزنين JSON {ar, en} مش جدول ترجمات، ومفيش سلة محذوفات ولا بحث ولا فلاتر حالة. محتاج قرار من صاحب Rides: نحوّلها لـ CatalogController + driver_service_translations + SoftDeletes؟',
                en: 'Not yet built like countries: name/description are JSON {ar, en} instead of a translations table, and there is no trash, search or status filters. NEEDS-DECISION (Rides owner): move it to CatalogController + driver_service_translations + SoftDeletes?',
            },
        ],
    },
};
