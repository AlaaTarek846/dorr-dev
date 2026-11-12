/**
 * Guide: human-assisted site requests inbox (admin.ai-site-requests.index).
 * Source of truth checked: views/ai-site-requests/index.vue, ModalRequestStatuses.vue,
 * Modules/AI/routes/admin.php (admin/v1/ai-site-requests), Http/Controllers/AiSiteServiceRequestAdminController.php
 * (index, summary, settings, updateSettings, statuses, storeStatus, updateStatus, destroyStatus),
 * Services/Sites/AiSiteServiceRequestService.php (enabled, create, notifyAdmins), Models/AiSiteRequestStatus.php,
 * Http/Resources/AiSiteServiceRequestResource.php, app/Http/Middleware/AdminScreenPermission.php.
 */
export default {
    route: 'admin.ai-site-requests.index',
    updated: '2026-11-10',

    ar: {
        summary: 'صندوق الطلبات اللي العملاء طلبوا فيها إن فريق حقيقي يبني موقعهم بدل أداة الذكاء الاصطناعي. من هنا بتشغّل الخدمة أو تقفلها، وبتظبط صلاحية العرض والنص التعريفي، وبتدير الحالات، وبتفتح أي طلب.',
        audience: 'فريق المبيعات أو تنفيذ المواقع. فتح الشاشة محتاج "مواقع بتدخل بشري: عرض"، وتشغيل الخدمة وحفظ الإعدادات وتعديل الحالات محتاج "تعديل".',
        purpose: [
            'تشوف كل طلب جديد أول ما يوصل (بيوصلك إشعار داخل اللوحة لو معاك صلاحية العرض).',
            'تفلتر الطلبات حسب الحالة وتعرف عدد كل حالة.',
            'تقفل أو تفتح الخدمة كلها للعملاء.',
            'تغيّر أسماء وألوان الحالات أو تضيف حالات خاصة بفريقك.',
        ],
        sections: [
            {
                title: 'كارت الإعدادات (فوق)',
                items: [
                    { name: 'تفعيل خيار التدخل البشري', desc: 'زرار تشغيل بيتحفظ فورًا. لو مقفول: الخيار بيختفي من التطبيق وأي طلب جديد بيترفض. الطلبات الموجودة بتكمل عادي. الكارت بيبقى إطاره أصفر وهو مقفول.' },
                    { name: 'صلاحية العرض (أيام)', desc: 'من 1 لـ 90. القيمة الافتراضية لعدد أيام صلاحية عرض السعر لو العرض اتبعت من غير مدة. شوف ملاحظة المطورين: فورم العرض بيبعت 7 دايمًا.' },
                    { name: 'نص تعريفي (عربي / إنجليزي)', desc: 'لحد 2000 حرف. النص اللي العميل بيشوفه في التطبيق قبل ما يملأ الطلب، كل لغة لوحدها. لو فاضي مفيش نص.' },
                    { name: 'حفظ', desc: 'يحفظ الثلاث خانات دول مع حالة التشغيل.' },
                ],
            },
            {
                title: 'شريط الفلاتر',
                items: [
                    { name: 'الكل', desc: 'كل الطلبات. الرقم جنبه مجموع كل الحالات.' },
                    { name: 'زرار لكل حالة مفعّلة', desc: 'بالترتيب اللي في "الحالات"، وجنبه عدد الطلبات اللي في الحالة دي. الحالات المتوقفة مش بتظهر هنا.' },
                    { name: 'البحث', desc: 'بالرقم المرجعي (WS-...) أو اسم المشروع. دوس Enter.' },
                    { name: 'الحالات', desc: 'زرار فوق يفتح نافذة إدارة الحالات.' },
                ],
            },
            {
                title: 'الجدول (دوس على أي صف يفتح الطلب)',
                items: [
                    { name: 'الرقم المرجعي', desc: 'كود زي WS-AB12CD3. لو جنبه رقم أحمر يبقى ده عدد رسايل العميل اللي لسه محدش قراها.' },
                    { name: 'المشروع', desc: 'اسم النشاط اللي العميل كتبه (أول 150 حرف).' },
                    { name: 'العميل', desc: 'اسم صاحب الطلب، أو رقمه لو مفيش اسم.' },
                    { name: 'الحالة', desc: 'اسم الحالة بلغة اللوحة ولونها.' },
                    { name: 'العرض', desc: 'سعر آخر عرض اتبعت وعملة العميل، أو "-" لو لسه مفيش عرض.' },
                    { name: 'التسليم', desc: 'موعد التسليم. بيتحدد بس بعد ما العميل يوافق على العرض ويدفع.' },
                    { name: 'تاريخ الطلب', desc: 'إمتى العميل بعت الطلب.' },
                ],
            },
            {
                title: 'نافذة الحالات',
                items: [
                    { name: 'الكود', desc: 'حروف إنجليزي صغيرة وأرقام و _ بس، ومينفعش يتكرر. بيتكتب مرة واحدة وقت الإضافة ومبيتغيرش.' },
                    { name: 'شارة "أساسية"', desc: 'الحالات اللي النظام بيشتغل عليها (تم الاستلام، قيد المراجعة، تم إرسال العرض، رفض العرض، جاري التنفيذ، متوقف، تم التسليم، مكتمل، مرفوض، ملغي). تقدر تغيّر اسمها ولونها وترتيبها بس، ومينفعش تتحذف أو تتقفل.' },
                    { name: 'الاسم بالعربي / بالإنجليزي', desc: 'إجباري، لحد 80 حرف. ده اللي العميل بيشوفه بلغته.' },
                    { name: 'اللون والترتيب ومفعّلة', desc: 'لون الشارة، وترتيبها في الفلاتر والقوايم. الحالة المتوقفة مش بتظهر في الفلاتر ولا في قايمة تغيير الحالة.' },
                    { name: 'حفظ / حذف / إضافة حالة', desc: 'كل صف بيتحفظ لوحده. الحذف مش هيتم لو الحالة أساسية أو فيه طلبات عليها.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تشغيل الخدمة لأول مرة',
                steps: [
                    'اكتب النص التعريفي بالعربي والإنجليزي، وحدد صلاحية العرض بالأيام.',
                    'فعّل "تفعيل خيار التدخل البشري" ودوس حفظ.',
                    'اتأكد إن الفريق اللي هيرد على الطلبات معاه صلاحية "مواقع بتدخل بشري: عرض" علشان يوصله إشعار كل طلب جديد.',
                ],
            },
            {
                title: 'متابعة الطلبات الجديدة',
                steps: [
                    'دوس فلتر "تم الاستلام".',
                    'افتح الطلب، اقرا بيانات العميل، وابعت عرض السعر من صفحة الطلب.',
                    'الرقم الأحمر جنب الرقم المرجعي معناه إن العميل كتبلك رسايل لسه متقريتش.',
                ],
            },
            {
                title: 'إضافة حالة خاصة (مثلًا "مراجعة التصميم")',
                steps: [
                    'دوس "الحالات" ثم "إضافة حالة".',
                    'اكتب الكود (design_review) والاسم بالعربي والإنجليزي واختار اللون.',
                    'دوس حفظ في نفس الصف. هتظهر في الفلاتر وفي قايمة تغيير الحالة جوه الطلب.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق العميل (يوزر ومقدم خدمة): خيار "اطلب فريق يبني موقعك" بيظهر أو يختفي، والنص التعريفي بيتعرض بلغته.' },
            { text: 'أسماء الحالات وألوانها بتظهر للعميل في متابعة طلبه وفي الإشعارات.' },
            { route: 'admin.ai-site-requests.show', text: 'صفحة الطلب: قايمة تغيير الحالة بتاخد الحالات المفعّلة من هنا، وعرض السعر بياخد صلاحية العرض الافتراضية.' },
        ],
        warnings: [
            { level: 'warning', text: 'قفل الخدمة بيمنع الطلبات الجديدة بس. الطلبات المفتوحة بتكمل، والعميل يقدر يدفع ويتابع عادي.' },
            { level: 'info', text: 'الخدمة كمان بتتقفل لو إعداد السيرفر ai.sites.enabled متقفل، حتى لو الزرار هنا مفتوح.' },
            { level: 'info', text: 'الحالات الأساسية بتحرك سير العمل (الدفعات والتسليم)، علشان كده مينفعش تتحذف أو تتقفل. لو حاولت تقفلها، السيرفر بيرجعها مفعّلة.' },
            { level: 'info', text: 'إشعارات الطلبات الجديدة بتوصل جوه اللوحة بس (مش على الموبايل) لكل أدمن معاه صلاحية العرض.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: الإعدادات بتظهر للقراءة، ونافذة الحالات من غير أزرار حفظ أو حذف أو إضافة.' },
        ],
    },

    en: {
        summary: 'Inbox of requests where customers asked a real team (not the AI builder) to build their website. Switch the service on or off, set the quote validity and intro text, manage statuses and open any request.',
        audience: 'Sales or site-delivery team. Opening the screen needs "Human-assisted sites: view"; switching the service, saving settings and editing statuses needs "update".',
        purpose: [
            'See every new request as it arrives (an in-panel notification goes to admins with the view permission).',
            'Filter requests by status and see the count per status.',
            'Turn the whole service on or off for customers.',
            'Rename/recolour statuses or add your own.',
        ],
        sections: [
            {
                title: 'Settings card (top)',
                items: [
                    { name: 'Offer the human-assisted option', desc: 'Saved immediately. Off: the option disappears from the app and new requests are refused; existing requests continue. The card gets a yellow border while off.' },
                    { name: 'Quote valid for (days)', desc: '1–90. Default validity of a quote sent without its own validity. See developer notes: the quote form always sends 7.' },
                    { name: 'Intro text (Arabic / English)', desc: 'Up to 2000 characters each. Shown to the customer in the app before the request form. Empty = no text.' },
                    { name: 'Save', desc: 'Saves these fields together with the on/off state.' },
                ],
            },
            {
                title: 'Filter bar',
                items: [
                    { name: 'All', desc: 'Every request; the number is the total of all statuses.' },
                    { name: 'One button per active status', desc: 'In the statuses order, with its request count. Inactive statuses are not listed.' },
                    { name: 'Search', desc: 'By reference (WS-…) or project name; press Enter.' },
                    { name: 'Statuses', desc: 'Opens the status manager.' },
                ],
            },
            {
                title: 'Table (click a row to open the request)',
                items: [
                    { name: 'Reference', desc: 'Code like WS-AB12CD3. A red number = unread customer messages.' },
                    { name: 'Project', desc: 'The business name the customer typed (first 150 characters).' },
                    { name: 'Customer', desc: 'Owner name, or id when there is no name.' },
                    { name: 'Status', desc: 'Status name in the panel language, with its colour.' },
                    { name: 'Quote', desc: 'Last quoted price and the customer currency, "-" if not quoted yet.' },
                    { name: 'Due', desc: 'Delivery date; only set once the customer accepts and pays.' },
                    { name: 'Created', desc: 'When the request was sent.' },
                ],
            },
            {
                title: 'Statuses modal',
                items: [
                    { name: 'Code', desc: 'Lowercase letters, digits and _ only; unique. Typed once when adding; cannot change later.' },
                    { name: '"Built-in" badge', desc: 'The workflow statuses (received, under review, quote sent, quote declined, in progress, on hold, delivered, completed, rejected, cancelled). Only name, colour and order can change; they cannot be deleted or switched off.' },
                    { name: 'Arabic / English name', desc: 'Required, up to 80 characters; what the customer sees in their language.' },
                    { name: 'Colour, order, active', desc: 'Badge colour and position. An inactive status is hidden from the filters and the status picker.' },
                    { name: 'Save / delete / add status', desc: 'Each row saves on its own. Delete is refused for built-in statuses or when requests use it.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Launch the service',
                steps: ['Write the intro text in Arabic and English and set the quote validity.', 'Switch on "Offer the human-assisted option" and save.', 'Give the team that answers requests "Human-assisted sites: view" so they get a notification per new request.'],
            },
            {
                title: 'Handle new requests',
                steps: ['Click the "Received" filter.', 'Open the request, read the brief and send a quote from the request page.', 'A red number next to the reference means unread customer messages.'],
            },
            {
                title: 'Add a custom status (e.g. "Design review")',
                steps: ['Click "Statuses", then "Add status".', 'Enter the code (design_review), the Arabic and English names and a colour.', 'Save the row. It appears in the filters and in the request status picker.'],
            },
        ],
        affects: [
            { text: 'Customer apps (users and providers): the "have a team build your site" option appears or disappears; the intro text is shown in their language.' },
            { text: 'Status names and colours are shown to the customer on their request and in notifications.' },
            { route: 'admin.ai-site-requests.show', text: 'Request page: the status picker lists the active statuses from here; the quote uses the default validity.' },
        ],
        warnings: [
            { level: 'warning', text: 'Switching the service off only blocks new requests. Open requests continue; customers can still pay and follow up.' },
            { level: 'info', text: 'The service is also off when the server setting ai.sites.enabled is off, whatever this switch says.' },
            { level: 'info', text: 'Built-in statuses drive the workflow (payments, delivery), so they cannot be deleted or switched off; the server forces them back on.' },
            { level: 'info', text: 'New-request notifications are in-panel only (no phone push), sent to every admin with the view permission.' },
            { level: 'info', text: 'With view only: the settings are read-only and the statuses modal has no save, delete or add buttons.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-site-requests/index.vue',
            'resources/js/modules/admin/themes/theme-1/views/ai-site-requests/ModalRequestStatuses.vue',
            'Modules/AI/app/Http/Controllers/AiSiteServiceRequestAdminController.php',
            'Modules/AI/app/Services/Sites/AiSiteServiceRequestService.php',
            'Modules/AI/app/Models/AiSiteServiceRequest.php, AiSiteRequestStatus.php, AiSiteServiceSetting.php',
            'Modules/AI/app/Http/Resources/AiSiteServiceRequestResource.php',
        ],
        api: [
            'GET  /api/admin/v1/ai-site-requests            (?status, search, page, per_page)',
            'GET  /api/admin/v1/ai-site-requests/summary    (counts per status + statuses)',
            'GET|PUT /api/admin/v1/ai-site-requests/settings (is_enabled, quote_valid_days 1-90, intro_ar, intro_en)',
            'GET|POST /api/admin/v1/ai-site-requests/statuses · PUT|DELETE …/statuses/{status}',
        ],
        tables: ['ai_site_service_requests', 'ai_site_request_statuses (code, name_ar, name_en, color, sort_order, is_active, is_system)', 'ai_site_service_settings', 'ai_site_request_messages (unread count)'],
        permissions: [
            'ai-site-requests.view (screen, list, summary, statuses, settings read)',
            'ai-site-requests.update (settings, storeStatus/updateStatus/destroyStatus — the middleware maps these method names to update, not create/delete)',
            'ai-site-requests.change-status (used on the request page)',
        ],
        decisions: [
            {
                ar: 'صلاحية العرض الافتراضية (quote_valid_days) عمليًا مش بتتستخدم من اللوحة: فورم العرض في صفحة الطلب بيبدأ بـ 7 ويبعتها دايمًا، فالسيرفر مش بيرجع للإعداد. محتاج قرار: الفورم يقرا القيمة من الإعدادات؟',
                en: 'The default quote validity (quote_valid_days) is effectively unused from the panel: the quote form on the request page starts at 7 and always sends it, so the server never falls back to the setting. NEEDS-DECISION: should the form read the setting?',
            },
        ],
        noTranslatableText: 'Status names are stored as name_ar / name_en columns (two fixed languages), and the intro text as intro_ar / intro_en — not the translations-table pattern.',
    },
};
