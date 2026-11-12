/**
 * Guide: freelance checklist templates (admin.freelance.checklistTemplates).
 * Source of truth checked: views/freelance/checklist-templates/index.vue, Modules/Freelance/routes/admin.php (freelance-checklist-templates),
 * Modules/Freelance/app/Http/Controllers/Admin/FreelanceChecklistTemplateController.php, Models/FreelanceChecklistTemplate(.Translation).php,
 * Http/Controllers/Client/ChecklistTemplateController.php (the app side), MilestoneDeliveries::MAX_CHECKS.
 */
export default {
    route: 'admin.freelance.checklistTemplates',
    updated: '2026-11-11',

    ar: {
        summary: 'قوائم تحقق جاهزة بيبدأ منها العميل وهو بيراجع جزء من المشروع قبل ما يعتمده، حسب نوع المشروع (التخصص)، بالعربي والإنجليزي.',
        audience: 'فريق تشغيل الشغل الحر. الفتح "قوالب قوائم التحقق: عرض"، والإضافة "إنشاء"، والتعديل والتفعيل "تعديل"، والحذف "حذف".',
        purpose: [
            'توفّر على العميل كتابة قايمة المراجعة من الصفر.',
            'تخصص قايمة لكل نوع مشروع، أو قايمة عامة لأي مشروع.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'اسم القالب بلغة اللوحة وتحته الكود.' },
                    { name: 'نوع المشروع', desc: 'التخصص اللي القالب ليه، أو "أي مشروع".' },
                    { name: 'نقاط التحقق', desc: 'عدد النقاط بلغة اللوحة.' },
                    { name: 'الحالة', desc: 'مفتاح تفعيل بيتحفظ فورًا (محتاج صلاحية التعديل). القالب المتوقف مش بيظهر للعميل.' },
                    { name: 'تعديل / حذف', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'نافذة القالب',
                items: [
                    { name: 'الكود', desc: 'إجباري، حروف إنجليزي صغيرة وأرقام و _ لحد 48، ومينفعش يتكرر بين القوالب الموجودة.' },
                    { name: 'نوع المشروع', desc: 'التخصصات المفعّلة بس، أو "أي مشروع".' },
                    { name: 'الاسم بالعربي / بالإنجليزي', desc: 'إجباري الاتنين، لحد 191 حرف.' },
                    { name: 'نقاط التحقق (عربي / إنجليزي)', desc: 'سطر لكل نقطة، لحد 200 حرف للنقطة، ومن 1 لحد الحد الأقصى المكتوب في التنبيه فوق. السطور الفاضية بتتشال قبل الحفظ.' },
                    { name: 'الترتيب ومفعّل', desc: 'ترتيب الظهور، وحالة القالب.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'إضافة قالب لمشاريع التصميم',
                steps: [
                    'دوس "إضافة قالب".',
                    'اكتب كود زي design_review واختار التخصص.',
                    'اكتب الاسم ونقاط التحقق باللغتين.',
                    'دوس حفظ. العميل هيلاقيه وهو بيراجع أي جزء في مشروع من التخصص ده.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق العميل: قايمة القوالب اللي بيبدأ منها قايمة التحقق لجزء من المشروع (المفعّلة بس، بلغته).' },
            { route: 'admin.freelance.specialties', text: 'التخصصات المفعّلة بس بتظهر في اختيار نوع المشروع.' },
        ],
        warnings: [
            { level: 'info', text: 'القوايم اللي العملاء بدأوها قبل كده مش بتتغير لو عدّلت أو مسحت القالب.' },
            { level: 'info', text: 'كل إضافة وتعديل وتفعيل وحذف بيتسجل في سجل المراجعة.' },
        ],
    },

    en: {
        summary: 'Ready-made checklists a client starts from when reviewing a part of a project before approving it, per kind of project (specialty), in Arabic and English.',
        audience: 'Freelance operations. Opening needs "Checklist templates: view"; adding create, editing and switching update, deleting delete.',
        purpose: [
            'Saves the client from writing the review list from scratch.',
            'One list per kind of project, or a general one for any project.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'Template name in the panel language, with its code below.' },
                    { name: 'Kind of project', desc: 'The specialty it belongs to, or "Any project".' },
                    { name: 'Checks', desc: 'Number of checks in the panel language.' },
                    { name: 'Status', desc: 'A switch saved at once (needs update). An inactive template is not offered to clients.' },
                    { name: 'Edit / Delete', desc: 'As your permissions allow.' },
                ],
            },
            {
                title: 'Template window',
                items: [
                    { name: 'Code', desc: 'Required: lowercase letters, digits and _, up to 48, unique among existing templates.' },
                    { name: 'Kind of project', desc: 'Active specialties only, or "Any project".' },
                    { name: 'Name (Arabic / English)', desc: 'Both required, up to 191 characters.' },
                    { name: 'Checks (Arabic / English)', desc: 'One line per check, up to 200 characters each, from 1 up to the maximum shown in the hint. Blank lines are dropped before saving.' },
                    { name: 'Order and active', desc: 'Display order and the template\'s status.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a template for design projects',
                steps: [
                    'Click "Add template".',
                    'Enter a code such as design_review and pick the specialty.',
                    'Write the name and the checks in both languages.',
                    'Save. Clients will find it when reviewing any part of a project of that specialty.',
                ],
            },
        ],
        affects: [
            { text: 'Client app: the templates a part\'s checklist starts from (active ones only, in the client\'s language).' },
            { route: 'admin.freelance.specialties', text: 'Only active specialties show in the kind-of-project picker.' },
        ],
        warnings: [
            { level: 'info', text: 'Checklists clients already started do not change when the template is edited or deleted.' },
            { level: 'info', text: 'Every add, edit, switch and delete is written to the audit log.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/checklist-templates/index.vue',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceChecklistTemplateController.php',
            'Modules/Freelance/app/Models/FreelanceChecklistTemplate.php, FreelanceChecklistTemplateTranslation.php',
            'Modules/Freelance/app/Http/Controllers/Client/ChecklistTemplateController.php (GET /api/mobile/v1/freelance/checklist-templates)',
        ],
        api: [
            'GET    /api/admin/v1/freelance-checklist-templates · GET …/options (specialties, max_checks)',
            'POST   /api/admin/v1/freelance-checklist-templates · PUT|DELETE …/{template}',
            'PATCH  /api/admin/v1/freelance-checklist-templates/{template}/status {status}',
        ],
        tables: ['freelance_checklist_templates (code, specialty_id, status, sort_order, soft deletes)', 'freelance_checklist_template_translations (locale, name, items — one check per line)'],
        permissions: ['freelance-checklist-templates.view (index, options)', 'freelance-checklist-templates.create (store)', 'freelance-checklist-templates.update (update, status)', 'freelance-checklist-templates.delete (destroy)'],
        decisions: [
            {
                ar: 'النصوص متخزنة في جدول ترجمات، بس الفورم بيعرض الحقول عربي/إنجليزي جنب بعض (ثابت على لغتين) بدل CatalogTranslationTabs، ومفيش سلة محذوفات ولا بحث في الشاشة. محتاج قرار من صاحب Freelance لو هتتحوّل لشكل الدول.',
                en: 'Texts live in a translations table, but the form shows fixed Arabic/English fields side by side instead of CatalogTranslationTabs, and the screen has no trash or search. NEEDS-DECISION (Freelance owner): move it to the countries layout?',
            },
        ],
    },
};
