/**
 * Guide: AI Marketing Director operations (admin.marketing.operations) — read only.
 * Source of truth checked: views/marketing/operations/index.vue, Modules/Marketing/routes/admin.php,
 * Modules/Marketing/app/Http/Controllers/Admin/OverviewController.php (subscriptions, projects, jobs, audit),
 * Modules/Marketing/app/Models/MarketingJob.php (statuses), locales adminMarketing.operations.
 */
export default {
    route: 'admin.marketing.operations',
    updated: '2026-11-11',

    ar: {
        summary: 'متابعة للقراءة بس: الاشتراكات، المشاريع، المهام (البحث والخطط)، وسجل العمليات. أرقام وحالات بس، من غير محتوى خطط أو أبحاث العملاء.',
        audience: 'فريق التشغيل والدعم. محتاج "تشغيل التسويق: عرض" بس، ومفيش أي زرار بيعدّل.',
        purpose: [
            'تعرف مين مشترك ولحد إمتى.',
            'تلاقي المهام اللي فشلت أو واقفة وكود الخطأ.',
            'تشوف سجل العمليات على مشروع معيّن.',
        ],
        sections: [
            {
                title: 'التبويبات',
                items: [
                    { name: 'الاشتراكات', desc: 'المالك، الباقة، الحالة، السعر، البداية والنهاية. فلتر "السارية بس".' },
                    { name: 'المشاريع', desc: 'الاسم والمجال، المالك، الدولة، عدد الأعضاء والأبحاث والخطط. فلتر نشط / مؤرشف. زرار السجل بيفتح سجل المشروع.' },
                    { name: 'المهام', desc: 'نوع المهمة (بحث / خطة)، المشروع، الحالة، كود الخطأ، عدد المحاولات، والتوقيتات. فلتر بالحالة.' },
                    { name: 'السجل', desc: 'كل عملية: الفعل، مين عمله، على إيه، رقم الطلب، والوقت. ابحث بمعرّف المشروع (UUID).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'متابعة مهام فاشلة',
                steps: [
                    'افتح تبويب المهام واختار "فشلت".',
                    'شوف كود الخطأ وعدد المحاولات.',
                    'دوس على المشروع عشان تشوف سجله.',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.packages', text: 'الباقة في الاشتراك هي كودها في شاشة الباقات.' },
        ],
        warnings: [
            { level: 'info', text: 'الشاشة للقراءة بس، ومش بتعرض محتوى الخطط أو الأبحاث (سياسة الخصوصية POL-13).' },
            { level: 'info', text: 'المبالغ بتتخزن بأصغر وحدة للعملة، والشاشة بتقسمها على 100.' },
        ],
    },

    en: {
        summary: 'A read-only watch: subscriptions, projects, jobs (research and plans) and the audit trail. Numbers and states only, never a customer\'s plans or research.',
        audience: 'Operations and support. Needs only "Marketing operations: view"; nothing here edits.',
        purpose: [
            'See who is subscribed and until when.',
            'Find failed or stuck jobs and their error code.',
            'Read the audit trail of one project.',
        ],
        sections: [
            {
                title: 'Tabs',
                items: [
                    { name: 'Subscriptions', desc: 'Owner, package, status, price, start and end. "Current only" filter.' },
                    { name: 'Projects', desc: 'Name and industry, owner, country, members / research runs / plans counts. Active / archived filter. The history button opens the project\'s audit.' },
                    { name: 'Jobs', desc: 'Job type (research / plan), project, status, error code, retries and times. Status filter.' },
                    { name: 'Audit', desc: 'Every action: what, who, on what, request id and when. Search by project UUID.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Follow up failed jobs',
                steps: [
                    'Open the Jobs tab and pick "Failed".',
                    'Read the error code and the retry count.',
                    'Click the project to read its audit trail.',
                ],
            },
        ],
        affects: [
            { route: 'admin.marketing.packages', text: 'The package of a subscription is its code on the Packages screen.' },
        ],
        warnings: [
            { level: 'info', text: 'Read only, and never shows plan or research content (privacy policy POL-13).' },
            { level: 'info', text: 'Amounts are stored in the minor unit; the screen divides by 100.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/marketing/operations/index.vue',
            'Modules/Marketing/app/Http/Controllers/Admin/OverviewController.php',
        ],
        api: [
            'GET /api/admin/v1/marketing/subscriptions?active=1',
            'GET /api/admin/v1/marketing/projects?status=',
            'GET /api/admin/v1/marketing/jobs?status=',
            'GET /api/admin/v1/marketing/audit?project=<uuid>',
        ],
        tables: ['marketing_subscriptions', 'marketing_projects', 'marketing_jobs', 'marketing_audit_events'],
        permissions: ['marketing-operations.view'],
        decisions: [],
    },
};
