/**
 * Guide: DORR AI WORLD ops console (admin.ai-world.index).
 * Source of truth checked: views/ai-world/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-world),
 * Http/Controllers/World/AiWorldAdminController.php, Services/World/AiWorldAccess.php, WorldPricingService.php,
 * AiWorldProjectService.php (max scenes, identity threshold, retries), AiWorldPrivacyService.php (retention,
 * purgeExpired, report), Models/World/AiWorldSetting.php, AiWorldCountryMode.php (DEFAULTS), AiWorldPrice.php,
 * AiWorldReport.php, AiWorldCostLedger.php.
 */
export default {
    route: 'admin.ai-world.index',
    updated: '2026-11-11',

    ar: {
        summary: 'لوحة تشغيل خدمة "عالم DORR" لفيديوهات الرحلات بالذكاء الاصطناعي: تشغيل الخدمة وحدودها، والأوضاع المتاحة في كل دولة، والسعر لكل ثانية، ومتابعة المشاريع وتكلفتها الداخلية، والرد على البلاغات.',
        audience: 'مدير المنتج والتسعير وفريق الدعم. الفتح محتاج "عالم DORR: عرض"، وأي حفظ أو تغيير حالة بلاغ محتاج "تعديل".',
        purpose: [
            'تشغيل أو إيقاف الخدمة لكل العملاء.',
            'تحديد الأوضاع (فيديو واحد، متعدد المدن، بوابة AR، جولة 3D) المتاحة في كل دولة.',
            'تسعير الثانية المولّدة لكل دولة وجودة.',
            'متابعة المشاريع: اتخصم كام واترجّع كام، وكل محاولة توليد حصل فيها إيه.',
            'الرد على الشكاوى وطلبات الحذف وبلاغات الانتحال.',
        ],
        sections: [
            {
                title: 'تاب نظرة عامة',
                items: [
                    { name: 'كارت لكل حالة', desc: 'عدد المشاريع في كل حالة (في انتظار التأكيد، قيد التوليد، قيد المراجعة، جاري الدمج، مكتمل، فشل، ملغي).' },
                    { name: 'كارت لكل عملة', desc: 'إجمالي المخصوم من العملاء وإجمالي المسترجع بنفس العملة، بعدد الخانات العشرية الحقيقي للعملة (3 للدينار الكويتي، 2 للجنيه).' },
                    { name: 'رقم أحمر على تاب البلاغات', desc: 'عدد البلاغات اللي لسه "مفتوح".' },
                ],
            },
            {
                title: 'تاب الإعدادات',
                items: [
                    { name: 'الخدمة مفعّلة', desc: 'لو مقفولة: كل الأوضاع بتبان مقفولة في التطبيق، وأي مشروع جديد بيترفض.' },
                    { name: 'أقصى عدد مشاهد في المشروع', desc: 'من 1 لـ 20. مشروع فيه مشاهد أكتر بيترفض.' },
                    { name: 'أقصى إعادات للمشهد', desc: 'من 0 لـ 5. لو الوش في الفيديو مش شبه صور العميل، أو التوليد فشل بخطأ ينفع يتعاد، النظام بيعيد لحد العدد ده من غير خصم زيادة.' },
                    { name: 'مدة الاحتفاظ (أيام)', desc: 'من 1 لـ 365. المشاريع اللي خلصت أو فشلت أو اتلغت من أكتر من المدة دي بتتمسح هي وملفاتها يوميًا، وصور العميل المرجعية بتنتهي بعدها.' },
                    { name: 'عتبة تطابق الهوية', desc: 'من 0 لـ 100. لو نسبة الشبه أقل منها المشهد بيتعاد. قيمة مبدئية، عايرها على صور حقيقية قبل ما تعتمد عليها.' },
                ],
            },
            {
                title: 'تاب الدول والأوضاع',
                items: [
                    { name: 'صف لكل دولة', desc: 'كود الدولة وعملتها، وعلامة لكل وضع. الدولة اللي ملهاش إعداد بتاخد الافتراضي: فيديو واحد ومتعدد المدن شغالين، وبوابة AR وجولة 3D مقفولين.' },
                    { name: 'حفظ', desc: 'يحفظ كل الدول مرة واحدة.' },
                ],
            },
            {
                title: 'تاب الأسعار',
                items: [
                    { name: 'صف لكل دولة × جودة', desc: 'standard وpremium لكل دولة.' },
                    { name: 'السعر للثانية (وحدات صغرى)', desc: 'رقم صحيح. 1000 في عملة بخانتين عشريتين = 10.00 للثانية. سعر الفيديو = عدد الثواني المولّدة × السعر ده.' },
                    { name: 'مفعّل', desc: 'لو الصف مش مفعّل (أو ملوش سعر)، العميل في الدولة دي مش هيقدر يطلب الجودة دي ("السعر غير متاح").' },
                    { name: 'حفظ', desc: 'بيحفظ بس الصفوف اللي ليها سعر أكبر من 0 أو متعلّم عليها "مفعّل".' },
                ],
            },
            {
                title: 'تاب المشاريع',
                items: [
                    { name: 'فلتر الحالة', desc: 'كل الحالات أو حالة واحدة.' },
                    { name: 'الأعمدة', desc: 'رقم المشروع، رقم المالك، الحالة، الثواني، المخصوم، المسترجع (بخانات عملة المشروع العشرية). المشاريع الممسوحة بتظهر كمان.' },
                    { name: 'التفاصيل', desc: 'سجل التكلفة الداخلي (النوع والمبلغ والملاحظة)، ومحاولات كل مشهد (رقم المشهد/المحاولة، الحالة، كود الخطأ). العميل عمره ما بيشوف السجل ده ولا أسماء المزودين.' },
                ],
            },
            {
                title: 'تاب البلاغات',
                items: [
                    { name: 'النوع', desc: 'شكوى، طلب حذف، انتحال.' },
                    { name: 'المشروع والملاحظات', desc: 'رقم المشروع (لو فيه) واللي العميل كتبه.' },
                    { name: 'الحالة', desc: 'مفتوح / قيد المراجعة / تم الحل / مرفوض. بتتحفظ أول ما تختار. "تم الحل" أو "مرفوض" بيسجّل مين الأدمن اللي قفله.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تشغيل الخدمة في دولة جديدة',
                steps: [
                    'في "الدول والأوضاع" علّم الأوضاع اللي عايزها للدولة ودوس حفظ.',
                    'في "الأسعار" اكتب سعر الثانية لجودة standard (وpremium لو هتبيعها) وعلّم "مفعّل" ودوس حفظ.',
                    'اتأكد إن "الخدمة مفعّلة" في الإعدادات.',
                ],
            },
            {
                title: 'متابعة شكوى عميل على فيديو',
                steps: [
                    'افتح "البلاغات" وغيّر الحالة لـ "قيد المراجعة".',
                    'خد رقم المشروع وافتحه من "المشاريع" ثم "التفاصيل": شوف المحاولات وأكواد الخطأ والمبالغ.',
                    'لما تخلص غيّر الحالة لـ "تم الحل" أو "مرفوض".',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق اليوزر ومقدم الخدمة: ظهور الخدمة، والأوضاع المتاحة في بلده، والحد الأقصى للمشاهد، وسعر الفيديو.' },
            { text: 'محفظة العميل: المشروع بيتخصم حسب الثواني × السعر، والمشاهد الفاشلة بتترجّع.' },
            { text: 'الاحتفاظ بالبيانات: المسح اليومي للمشاريع القديمة وصور الهوية.' },
        ],
        warnings: [
            { level: 'danger', text: 'تقليل "مدة الاحتفاظ" بيخلي المسح اليومي الجاي يمسح كل المشاريع المنتهية الأقدم من المدة الجديدة هي وفيديوهاتها، ومفيش استرجاع.' },
            { level: 'warning', text: 'لو دولة عندها وضع مفعّل ومفيش سعر مفعّل لها، العميل هيشوف الوضع بس مش هيقدر يكمل الطلب.' },
            { level: 'warning', text: 'في تاب الأسعار، الصف اللي سعره 0 ومش مفعّل مش بيتبعت خالص، فلو كان متسجل قبل كده بيفضل زي ما هو. علشان توقف سعر، شيل علامة "مفعّل" وسيب السعر.' },
            { level: 'info', text: 'تغيير حالة البلاغ لـ "تم الحل" مش بيعمل أي حاجة لوحده (مثلًا مش بيمسح المشروع في طلب الحذف). الإجراء نفسه لازم يتعمل بإيدك.' },
            { level: 'info', text: 'تاب المشاريع بيعرض أول صفحة بس (أحدث المشاريع).' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: كل الحقول والعلامات والقوايم بتبقى للقراءة ومن غير أزرار حفظ.' },
        ],
    },

    en: {
        summary: 'Ops console for "DORR World" AI trip videos: service on/off and limits, which modes each country gets, the price per generated second, projects with their internal cost, and reports.',
        audience: 'Product/pricing owner and support. Opening needs "DORR World: view"; any save or report status change needs "update".',
        purpose: [
            'Turn the service on or off for every customer.',
            'Choose the modes (single video, multi-city, AR portal, 3D tour) per country.',
            'Price the generated second per country and quality.',
            'Follow projects: charged, refunded, and what happened on every generation attempt.',
            'Answer complaints, deletion requests and impersonation reports.',
        ],
        sections: [
            {
                title: 'Overview tab',
                items: [
                    { name: 'One card per status', desc: 'Projects per status (awaiting confirmation, generating, in review, stitching, completed, failed, cancelled).' },
                    { name: 'One card per currency', desc: 'Total charged to and refunded to customers in that currency, shown with the currency’s real decimal places (3 for KWD, 2 for EGP).' },
                    { name: 'Red number on Reports', desc: 'Reports still "open".' },
                ],
            },
            {
                title: 'Settings tab',
                items: [
                    { name: 'Service enabled', desc: 'Off: every mode shows as off in the app and new projects are refused.' },
                    { name: 'Max scenes per project', desc: '1–20; larger projects are refused.' },
                    { name: 'Max retries per scene', desc: '0–5. When the face does not match the customer photos, or a retryable error happens, the scene is regenerated up to this many times at no extra charge.' },
                    { name: 'Retention (days)', desc: '1–365. Finished/failed/cancelled projects older than this are deleted daily with their files; reference photo sets expire after it.' },
                    { name: 'Identity threshold', desc: '0–100. A match score below it triggers a retry. Starting value — calibrate on real photos first.' },
                ],
            },
            {
                title: 'Countries & modes tab',
                items: [
                    { name: 'One row per country', desc: 'Code, currency and a tick per mode. A country never saved uses the defaults: single and multi on, AR portal and 3D tour off.' },
                    { name: 'Save', desc: 'Saves every country at once.' },
                ],
            },
            {
                title: 'Prices tab',
                items: [
                    { name: 'One row per country × quality', desc: 'standard and premium for each country.' },
                    { name: 'Price per second (minor units)', desc: 'Integer. 1000 on a 2-decimal currency = 10.00 per second. Video price = generated seconds × this.' },
                    { name: 'Active', desc: 'Inactive or missing = customers in that country cannot order that quality ("price missing").' },
                    { name: 'Save', desc: 'Only rows with a price above 0 or ticked active are sent.' },
                ],
            },
            {
                title: 'Projects tab',
                items: [
                    { name: 'Status filter', desc: 'All or one status.' },
                    { name: 'Columns', desc: 'Project id, owner id, status, seconds, charged, refunded (in the project currency’s decimal places). Deleted projects are listed too.' },
                    { name: 'Details', desc: 'Internal cost ledger (type, amount, note) and each scene attempt (scene/attempt, status, error code). Customers never see the ledger or provider names.' },
                ],
            },
            {
                title: 'Reports tab',
                items: [
                    { name: 'Type', desc: 'Complaint, deletion request, impersonation.' },
                    { name: 'Project & notes', desc: 'Project id (if any) and what the customer wrote.' },
                    { name: 'Status', desc: 'Open / reviewing / resolved / dismissed; saved on change. Resolved or dismissed records the admin who closed it.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Open the service in a new country',
                steps: ['In "Countries & modes" tick the modes for the country and save.', 'In "Prices" enter the per-second price for standard (and premium if sold), tick "Active" and save.', 'Make sure "Service enabled" is on in Settings.'],
            },
            {
                title: 'Follow a customer complaint about a video',
                steps: ['Open "Reports" and set it to "Reviewing".', 'Take the project id, open it under "Projects" → "Details": attempts, error codes, amounts.', 'When done set it to "Resolved" or "Dismissed".'],
            },
        ],
        affects: [
            { text: 'User and provider apps: whether the service shows, the modes in their country, the max scenes and the video price.' },
            { text: 'Customer wallet: charged by seconds × price; failed scenes are refunded.' },
            { text: 'Data retention: the daily purge of old projects and identity photos.' },
        ],
        warnings: [
            { level: 'danger', text: 'Lowering "Retention" makes the next daily purge delete every finished project older than the new value, with its videos — no undo.' },
            { level: 'warning', text: 'A country with a mode on but no active price shows the mode but the customer cannot complete the order.' },
            { level: 'warning', text: 'On the Prices tab a row with 0 and not active is not sent at all, so an older saved row stays as it was. To stop a price, untick "Active" and keep the price.' },
            { level: 'info', text: 'Setting a report to "Resolved" does nothing by itself (e.g. it does not delete the project for a deletion request); the action itself is manual.' },
            { level: 'info', text: 'The Projects tab shows the first page only (latest projects).' },
            { level: 'info', text: 'With view only: every field, tick and picker is read-only and there are no save buttons.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-world/index.vue',
            'Modules/AI/app/Http/Controllers/World/AiWorldAdminController.php',
            'Modules/AI/app/Services/World/AiWorldAccess.php, WorldPricingService.php, AiWorldProjectService.php, AiWorldPrivacyService.php',
            'Modules/AI/app/Models/World/AiWorldSetting.php, AiWorldCountryMode.php, AiWorldPrice.php, AiWorldProject.php, AiWorldReport.php, AiWorldCostLedger.php',
        ],
        api: [
            'GET  /api/admin/v1/ai-world/stats (stats, projects and projects/{id} also return top-level currency_decimals {code: places})',
            'GET|PUT /api/admin/v1/ai-world/settings          (is_enabled, max_scenes_per_project, max_retries_per_scene, identity_threshold, retention_days, default_provider_id)',
            'GET|PUT /api/admin/v1/ai-world/country-modes',
            'GET|PUT /api/admin/v1/ai-world/prices',
            'GET  /api/admin/v1/ai-world/projects (?status, owner_id; paginated, withTrashed) · GET …/projects/{id}',
            'GET  /api/admin/v1/ai-world/reports (?status) · PUT …/reports/{id} (status, notes)',
            'Apps: /api/{user|provider}/v1/ai-world/*',
        ],
        tables: ['ai_world_settings', 'ai_world_country_modes', 'ai_world_prices (price_per_second_minor)', 'ai_world_projects', 'ai_world_cost_ledger', 'ai_world_reports'],
        permissions: [
            'ai-world.view (screen, every list)',
            'ai-world.update (settings, country modes, prices, report status)',
        ],
        decisions: [
            {
                ar: 'تاب المشاريع مفيهوش ترقيم صفحات رغم إن الـ API بيرجّع صفحات، وفلتر الحالة مفيهوش "مسودة".',
                en: 'The Projects tab has no pagination although the API is paginated, and the status filter lacks "draft".',
            },
            {
                ar: 'default_provider_id بيتقبل في الـ API بس مفيش له حقل في الشاشة.',
                en: 'default_provider_id is accepted by the API but has no field on the screen.',
            },
        ],
    },
};
