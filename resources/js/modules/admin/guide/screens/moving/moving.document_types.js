/**
 * Guide: provider document types (admin.moving.document_types).
 * Source of truth checked: views/moving/document-types/index.vue, Modules/Provider/routes/admin.php (provider-document-types),
 * Modules/Provider/app/Http/Controllers/Admin/ProviderDocumentTypeAdminController.php, Modules/Provider/app/Enums
 * (DocumentSubject, DocumentKind, EstablishmentType), AdminPermissionSeeder (provider-document-types).
 */
export default {
    route: 'admin.moving.document_types',
    updated: '2026-11-12',

    ar: {
        summary: 'هنا بتحدد المستندات اللي مقدم الخدمة بيرفعها (للمنشأة أو العربية أو السواق)، وشكلها، وفين تبقى مطلوبة: في أنهي دولة وأنهي خدمة ونوع عربية ونوع منشأة.',
        audience: 'مدير تشغيل النقل أو فريق المراجعة. الفتح محتاج "أنواع المستندات: عرض"، والإضافة "إنشاء"، والتعديل "تعديل"، والحذف "حذف".',
        purpose: [
            'تطبيق مقدمي الخدمة بيطلب المستندات المطلوبة بس حسب دولته وخدمته ونوعه.',
            'المنشأة أو العربية مبتتوافقش غير لما مستنداتها المطلوبة تترفع وتتراجع.',
        ],
        sections: [
            {
                title: 'نافذة الإضافة والتعديل',
                items: [
                    { name: 'الاسم والتعليمات (ع / EN)', desc: 'الاسم إجباري، والتعليمات بتظهر لمقدم الخدمة وهو بيرفع.' },
                    { name: 'الكود', desc: 'كود ثابت بالإنجليزي، لحد 48 حرف.' },
                    { name: 'يخص', desc: 'المنشأة أو العربية أو السواق.' },
                    { name: 'النوع', desc: 'مستند أو صورة.' },
                    { name: 'له رقم / له تاريخ انتهاء / محتاج الوش التاني', desc: 'الخانات اللي بتتطلب مع الرفع. لو له انتهاء تقدر تحدد قبلها بكام يوم يتبعت تنبيه (0 لـ 365).' },
                    { name: 'مطلوب في', desc: 'سطر لكل دولة: الخدمة (أو أي خدمة)، ونوع العربية (للمستندات اللي تخص العربية بس)، ونوع المنشأة (فرد أو شركة أو الاتنين). من غير سطور المستند اختياري في كل مكان.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'طلب رخصة العربية في دولة',
                steps: [
                    'دوس إضافة، واكتب الاسم باللغتين والكود.',
                    'اختار "العربية" في يخص، وفعّل له رقم وله تاريخ انتهاء.',
                    'في "مطلوب في" ضيف سطر للدولة، واختار الخدمة أو سيبها أي خدمة، ودوس حفظ.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق مقدمي الخدمة: قايمة المستندات المطلوبة للمنشأة والعربية والسواق.' },
            { route: 'admin.moving.establishments', text: 'مراجعة المستندات والموافقة على المنشأة والعربيات.' },
        ],
        warnings: [
            { level: 'danger', text: 'نوع مستند اترفع عليه أي مستند، أو صورة اللوحة، مينفعش يتحذف. اقفله بدل ما تحذفه.' },
            { level: 'warning', text: 'إضافة مستند مطلوب جديد بتخلي المنشآت اللي في نفس الدولة والخدمة محتاجة ترفعه.' },
        ],
    },

    en: {
        summary: 'Decide the papers a provider uploads (for the establishment, the vehicle or the driver), their shape, and where each is required: country, service, vehicle type and establishment type.',
        audience: 'The moving operations manager or the review team. Opening needs "Document types: view"; adding needs create, editing update, deleting delete.',
        purpose: [
            'The providers app asks only for the papers required in its country, service and type.',
            'An establishment or vehicle is approved only after its required papers are uploaded and reviewed.',
        ],
        sections: [
            {
                title: 'Add / edit window',
                items: [
                    { name: 'Name and instructions (AR / EN)', desc: 'The name is required; the instructions show to the provider while uploading.' },
                    { name: 'Code', desc: 'A fixed English code, up to 48 characters.' },
                    { name: 'Subject', desc: 'Establishment, vehicle or driver.' },
                    { name: 'Kind', desc: 'Document or photo.' },
                    { name: 'Has a number / has an expiry / needs the back side', desc: 'Fields asked with the upload. With an expiry you can set how many days before it an alert goes out (0–365).' },
                    { name: 'Required in', desc: 'One line per country: the service (or any), the vehicle type (vehicle papers only), and the establishment type (individual, company or both). With no lines the paper is optional everywhere.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Require the vehicle licence in a country',
                steps: [
                    'Press Add and enter the name in both languages and the code.',
                    'Pick "Vehicle" as the subject; switch on has a number and has an expiry.',
                    'Under "Required in" add a line for the country, pick the service or leave any, and save.',
                ],
            },
        ],
        affects: [
            { text: 'Providers app: the papers required for the establishment, vehicles and drivers.' },
            { route: 'admin.moving.establishments', text: 'Reviewing papers and approving establishments and vehicles.' },
        ],
        warnings: [
            { level: 'danger', text: 'A type any paper was uploaded under, or the plate photo, cannot be deleted. Switch it off instead.' },
            { level: 'warning', text: 'A new required paper makes establishments in that country and service need to upload it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/moving/document-types/index.vue',
            'Modules/Provider/app/Http/Controllers/Admin/ProviderDocumentTypeAdminController.php',
        ],
        api: [
            'GET    /api/admin/v1/provider-document-types · GET …/options',
            'POST   /api/admin/v1/provider-document-types · PUT|DELETE …/provider-document-types/{id}',
        ],
        tables: ['provider_document_types (+ translations)', 'provider_document_requirements', 'provider_documents'],
        permissions: ['provider-document-types.view', 'provider-document-types.create', 'provider-document-types.update', 'provider-document-types.delete'],
    },
};
