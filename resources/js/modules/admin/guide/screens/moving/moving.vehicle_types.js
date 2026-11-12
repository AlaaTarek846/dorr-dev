/**
 * Guide: moving vehicle types (admin.moving.vehicle_types).
 * Source of truth checked: views/moving/vehicle-types/index.vue, Modules/Provider/routes/admin.php (provider-vehicle-types),
 * Modules/Provider/app/Http/Controllers/Admin/ProviderVehicleTypeAdminController.php,
 * Modules/Moving/app/Services/Requests/MovingRequestService.php (reachingBranches), AdminPermissionSeeder (provider-vehicle-types).
 */
export default {
    route: 'admin.moving.vehicle_types',
    updated: '2026-11-12',

    ar: {
        summary: 'هنا بتدير أنواع العربيات اللي المنشآت بتسجّلها والعميل بيختار منها وهو بيطلب النقل (ربع نقل، نص نقل، جامبو...): الاسم والصورة والترتيب، والدول اللي النوع موجود فيها، والخدمات اللي يشتغل فيها.',
        audience: 'مدير تشغيل النقل. الفتح محتاج "أنواع المركبات: عرض"، والإضافة "إنشاء"، والتعديل "تعديل"، والحذف "حذف".',
        purpose: [
            'العميل بيختار نوع أو أكتر للطلب، والطلب بيوصل بس للفروع اللي شركتها عندها عربية متوافق عليها من نوع منهم.',
            'المنشأة بتختار النوع وهي بتضيف عربيتها، وبتشوف بس الأنواع المتاحة في دولتها وخدمتها.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'الصورة والاسم بلغة اللوحة والكود تحته.' },
                    { name: 'الدول', desc: 'الدول اللي النوع ظاهر فيها.' },
                    { name: 'الخدمات', desc: 'الخدمات اللي النوع يشتغل فيها (زي نقل العفش).' },
                    { name: 'الترتيب والحالة', desc: 'ترتيب الظهور في التطبيقات، والنوع المتوقف مبيظهرش للعميل ولا للمنشأة.' },
                ],
            },
            {
                title: 'نافذة الإضافة والتعديل',
                items: [
                    { name: 'الاسم (ع / EN)', desc: 'إجباري، لحد 120 حرف. الوصف اختياري.' },
                    { name: 'الكود', desc: 'كود ثابت بالإنجليزي (مثلًا half_truck)، لحد 48 حرف.' },
                    { name: 'الصورة', desc: 'صورة العربية اللي بتظهر للعميل في اختيار النوع.' },
                    { name: 'الدول والخدمات', desc: 'علّم الدول والخدمات اللي النوع متاح فيها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة نوع عربية جديد',
                steps: [
                    'دوس إضافة.',
                    'اكتب الاسم باللغتين والكود، وارفع الصورة.',
                    'علّم الدول وخدمة نقل العفش، وسيبه مفعّل، ودوس حفظ.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق العميل: اختيار نوع العربية أو أكتر في طلب النقل.' },
            { text: 'تطبيق مقدمي الخدمة: إضافة العربية، ووصول الطلبات للفروع.' },
            { route: 'admin.moving.document_types', text: 'المستندات المطلوبة ممكن تتحدد لنوع عربية معيّن.' },
        ],
        warnings: [
            { level: 'danger', text: 'النوع اللي عليه أي عربية (حتى المحذوفة) مينفعش يتحذف. اقفله بدل ما تحذفه.' },
            { level: 'warning', text: 'قفل النوع بيوقف وصول الطلبات الجديدة اللي بتطلبه بس.' },
        ],
    },

    en: {
        summary: 'Manage the kinds of vehicle establishments register and customers pick from when they ask for a move (quarter truck, half truck, jumbo…): name, image and order, the countries it exists in, and the services it works in.',
        audience: 'The moving operations manager. Opening needs "Vehicle types: view"; adding needs create, editing update, deleting delete.',
        purpose: [
            'The customer picks one type or more for the request; it reaches only branches whose company has an approved vehicle of one of them.',
            'An establishment picks the type when it adds a vehicle, and sees only the types open in its country and service.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'Image, name in the panel language, and the code under it.' },
                    { name: 'Countries', desc: 'Where the type is shown.' },
                    { name: 'Services', desc: 'The services it works in (such as furniture moving).' },
                    { name: 'Order and status', desc: 'The order in the apps; an inactive type is hidden from customers and establishments.' },
                ],
            },
            {
                title: 'Add / edit window',
                items: [
                    { name: 'Name (AR / EN)', desc: 'Required, up to 120 characters. The description is optional.' },
                    { name: 'Code', desc: 'A fixed English code (e.g. half_truck), up to 48 characters.' },
                    { name: 'Image', desc: 'The vehicle picture customers see when picking the type.' },
                    { name: 'Countries and services', desc: 'Tick where the type is available.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a vehicle type',
                steps: [
                    'Press Add.',
                    'Enter the name in both languages and the code, and upload the image.',
                    'Tick the countries and the furniture-moving service, keep it active, and save.',
                ],
            },
        ],
        affects: [
            { text: 'Customer app: picking one vehicle type or more in a moving request.' },
            { text: 'Providers app: adding a vehicle, and which branches requests reach.' },
            { route: 'admin.moving.document_types', text: 'Required papers can target one vehicle type.' },
        ],
        warnings: [
            { level: 'danger', text: 'A type any vehicle uses (even a deleted one) cannot be deleted. Switch it off instead.' },
            { level: 'warning', text: 'Switching a type off only stops new requests that ask for it from reaching anyone.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/moving/vehicle-types/index.vue',
            'Modules/Provider/app/Http/Controllers/Admin/ProviderVehicleTypeAdminController.php',
            'Modules/Moving/app/Services/Requests/MovingRequestService.php (reachingBranches)',
        ],
        api: [
            'GET    /api/admin/v1/provider-vehicle-types · GET …/options',
            'POST   /api/admin/v1/provider-vehicle-types · POST|DELETE …/provider-vehicle-types/{id}',
        ],
        tables: ['provider_vehicle_types (+ translations, countries, services)', 'provider_vehicles (vehicle_type_id)', 'moving_requests (vehicle_type_id, vehicle_type_ids)'],
        permissions: ['provider-vehicle-types.view', 'provider-vehicle-types.create', 'provider-vehicle-types.update', 'provider-vehicle-types.delete'],
    },
};
