/**
 * Guide: AI plans (admin.ai-plans.index).
 * Source of truth checked: views/ai-plans/*.vue, Modules/AI/app/Models/AiPlan.php (translations, isAvailable,
 * resolvedPriceFor), Repositories/AiPlanRepository.php (trash rules), Services/AiChatUsageGuard.php,
 * Services/AiMediaQuotaService.php, Services/AiSubscriptionPurchaseService.php, Http/Resources/AiPlanResource.php.
 */
export default {
    route: 'admin.ai-plans.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتعمل الباقات اللي بيشترك فيها اليوزر ومقدم الخدمة والكابتن علشان يستخدموا المساعد الذكي: كام دقيقة، وكام صورة وفيديو، وكام موقع، وبكام في كل دولة. واسم الباقة ووصفها ومميزاتها مكتوبين بكل لغة.',
        audience: 'مسؤول التسعير أو التسويق، ومدير المنصة. محتاج صلاحيات "خطط الذكاء الاصطناعي": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'التجربة المجانية: أول ما يوزر جديد يفتح الشات بياخد تلقائيًا أول خطة "تجربة" مفعّلة حسب الترتيب.',
            'الباقات المدفوعة: اللي بتظهر في صفحة الباقات عند اليوزر ومقدم الخدمة والكابتن. الدفع من المحفظة بالـ PIN.',
            'نص الباقة بكل لغة: التطبيق بيعرض الاسم والوصف والشارة والمميزات بلغة اليوزر.',
            'تسعير حسب الدولة: نفس الباقة بسعر وعملة مختلفين لكل دولة.',
            'عروض وخصومات: سعر قبل الخصم، وشارة، وتمييز الباقة كـ "مميزة".',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'البحث', desc: 'بالاسم (في أي لغة) أو بالكود.' },
                    { name: 'الكل / مفعّلة / متوقفة', desc: 'فلترة سريعة، وجنب كل واحدة العدد.' },
                    { name: 'المحذوفات', desc: 'بتظهر لو فيه خطط في السلة. منها تقدر ترجّع خطة أو تحذفها نهائيًا.' },
                    { name: 'حذف المحدد', desc: 'تحدد كذا خطة من مربعات الاختيار وتنقلهم للسلة مرة واحدة.' },
                    { name: 'تنبيه "مفيش تجربة مجانية"', desc: 'بيظهر فوق الجدول لو مفيش خطة تجربة مفعّلة.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الاسم', desc: 'بلغة لوحة التحكم. دوس عليه يفتح التعديل. تحته: رقم الخطة، وشارة "تجربة"، ونجمة لو مميزة، والشارة، ونسبة الخصم.' },
                    { name: 'الكود', desc: 'معرّف ثابت للخطة.' },
                    { name: 'دقائق الاستخدام', desc: 'مدة نافذة الاستخدام. "غير محدود" لو 0.' },
                    { name: 'السعر', desc: 'السعر الأساسي وعملته، وتحته السعر قبل الخصم لو موجود.' },
                    { name: 'المدة', desc: 'الاشتراك بيخلص بعد كام يوم.' },
                    { name: 'الحالة', desc: 'زرار تشغيل وإيقاف سريع (محتاج صلاحية "تغيير الحالة").' },
                    { name: '🌍 إدارة الأسعار', desc: 'أسعار الخطة لكل دولة. من غير صلاحية التعديل بتفتح للقراءة بس.' },
                    { name: '🗑️ حذف', desc: 'ينقل الخطة لسلة المحذوفات. شوف التنبيهات.' },
                ],
            },
            {
                title: 'فورم الخطة: تابات اللغات',
                items: [
                    { name: 'تاب لكل لغة', desc: 'زي فورم الدول. التاب بيحمر لو فيه غلط في اللغة دي، وبيخضر لو كله تمام.' },
                    { name: 'الاسم', desc: 'إجباري في كل لغة.' },
                    { name: 'الشارة', desc: 'اختيارية، مثلًا "الأكثر طلبًا".' },
                    { name: 'الوصف', desc: 'اختياري، وبيظهر في صفحة الأسعار.' },
                    { name: 'المميزات', desc: 'قايمة نقط لكل لغة لوحدها، لحد 12 ميزة. اكتب الميزة ودوس Enter أو "إضافة".' },
                ],
            },
            {
                title: 'فورم الخطة: باقي الحقول',
                items: [
                    { name: 'الكود', desc: 'إجباري ومينفعش يتكرر.' },
                    { name: 'دقائق الاستخدام', desc: 'أول ما الدقايق تخلص الجلسة بتتقفل. 0 = من غير حد.' },
                    { name: 'دقائق الانتظار', desc: 'بعد ما الدقايق تخلص، اليوزر يستنى المدة دي لحد ما نافذة جديدة تفتح.' },
                    { name: 'حد الصور اليومي', desc: 'فاضي = من غير حد، و0 = توليد الصور مش ضمن الباقة.' },
                    { name: 'حد الفيديو اليومي وأقصى ثواني', desc: 'لو أي رقم فيهم = 0 يبقى الفيديو مش ضمن الباقة. المدة الفعلية على حسب المزود.' },
                    { name: 'مشاريع المواقع وتوليداتها اليومية', desc: 'لو أي رقم فيهم = 0 يبقى بناء المواقع مش ضمن الباقة، والعميل ممكن يشتري موقع لوحده.' },
                    { name: 'السعر ونسبة الخصم والسعر قبل الخصم', desc: 'اكتب أي اتنين منهم والتالت بيتحسب لوحده.' },
                    { name: 'العملة والمدة بالأيام', desc: 'عملة السعر الأساسي ومدة الاشتراك.' },
                    { name: 'الترتيب ومميزة وتجربة والحالة', desc: 'أول خطة تجربة مفعّلة في الترتيب هي اللي بتتدّى لليوزر الجديد.' },
                ],
            },
            {
                title: 'نافذة إدارة الأسعار',
                items: [
                    { name: 'صف لكل دولة', desc: 'فيه سعر ونسبة خصم وسعر قبل الخصم، وبتحفظ كل صف لوحده.' },
                    { name: 'شارة "أساسي" / "مخصص"', desc: '"أساسي" يعني الدولة بتستخدم سعر الخطة. "مخصص" يعني ليها سعر خاص، ولو مسحته بترجع للأساسي.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تجهيز التجربة المجانية (أول تشغيل)',
                steps: [
                    'دوس "إضافة خطة".',
                    'في كل تاب لغة اكتب الاسم (والوصف والمميزات لو حابب).',
                    'حط الكود، ودقائق الاستخدام (مثلًا 30) والانتظار، وحدود الصور والفيديو والمواقع.',
                    'السعر 0، وفعّل "تجربة" و"الحالة"، ودوس حفظ.',
                ],
            },
            {
                title: 'ترجمة خطة موجودة',
                steps: [
                    'دوس على اسم الخطة.',
                    'انقل بين تابات اللغات، وعدّل الاسم والشارة والوصف والمميزات في كل تاب.',
                    'دوس حفظ. التطبيق هيعرض كل لغة لليوزر بتاعها.',
                ],
            },
            {
                title: 'باقة مدفوعة بسعر مختلف في السعودية',
                steps: [
                    'أضف الخطة بسعرها الأساسي وعملتها واحفظها.',
                    'دوس 🌍 "إدارة الأسعار" على الخطة.',
                    'في صف السعودية حط السعر بالريال ودوس حفظ الصف.',
                ],
            },
            {
                title: 'إيقاف خطة أو ترجيعها من السلة',
                steps: [
                    'للإيقاف المؤقت: اقفل زرار الحالة. الخطة مش هتظهر لمشترك جديد.',
                    'لو حذفتها: دوس فلتر "المحذوفات"، ودوس ↩️ ترجيع جنب الخطة.',
                ],
            },
        ],
        affects: [
            { text: 'صفحة الباقات والاشتراك عند اليوزر ومقدم الخدمة والكابتن (DORR Driver AI) على Android وiOS والويب. النص بيوصل بلغة التطبيق نفسها.' },
            { text: 'شات المساعد: رسالة "خلصت دقايقك"، ووقت الانتظار، واسم الخطة اللي ظاهر فوق.' },
            { text: 'توليد الصور والفيديو وبناء المواقع: بتتقفل أو تتفتح على حسب حدود الخطة.' },
            { route: 'admin.ai-subscriptions.index', text: 'اشتراكات الذكاء الاصطناعي: كل اشتراك مربوط بخطة من هنا.' },
            { route: 'admin.ai-usage-sessions.index', text: 'جلسات الاستخدام: الدقايق اللي اتصرفت من كل خطة.' },
            { route: 'admin.ai-trial-control.index', text: 'التحكم في التجربة المجانية: مين خد التجربة وحالتها.' },
            { text: 'المحفظة وإشعارات الاشتراك: بتستخدم اسم الخطة.' },
        ],
        warnings: [
            { level: 'danger', text: 'لو مفيش خطة تجربة مفعّلة، أي يوزر جديد هيتقفل عليه المساعد خالص. الشاشة بتنبهك فوق الجدول.' },
            { level: 'warning', text: 'الحذف بينقل الخطة للسلة: مش هتظهر لمشترك جديد ومش هتتجدد، والمشتركين الحاليين يكملوا لحد نهاية مدتهم. تقدر ترجّعها في أي وقت.' },
            { level: 'warning', text: 'الحذف النهائي من السلة مش هيتم لو الخطة عليها اشتراكات، والشاشة هتقولك السبب.' },
            { level: 'warning', text: 'تعديل الدقايق أو الحدود بيسري على المشتركين الحاليين كمان، لأن الحدود بتتقري من الخطة وقت الاستخدام. أما المدة بالأيام فبتتثبت وقت الاشتراك.' },
            { level: 'info', text: 'الاسم إجباري في كل لغة متفعّلة. لو ضفت لغة جديدة للمنصة، افتح كل خطة واكتب لها النص.' },
            { level: 'info', text: 'التجربة المجانية بتتدّى مرة واحدة بس. لما دقايقها تخلص حالتها بتبقى "انتهت" ومش بترجع تفتح.' },
            { level: 'info', text: 'لو التجديد فشل، الاشتراك بيتعلّق بعد مهلة 3 أيام.' },
        ],
    },

    en: {
        summary: 'Build the plans users, providers and captains subscribe to for the AI assistant: minutes, images, videos, sites and the price in each country. The plan name, description and features are written per language.',
        audience: 'Pricing or marketing owner, and the platform owner. Needs the "AI plans" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Free trial: a new user opening the chat automatically gets the first active "trial" plan by sort order.',
            'Paid plans: shown on the plans page for users, providers and captains; paid from the wallet with the PIN.',
            'Text in every language: the apps show the name, description, badge and features in the user’s language.',
            'Per-country pricing: the same plan with a different price and currency per country.',
            'Offers: original price, badge, "featured" highlight.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Search', desc: 'By name (any language) or code.' },
                    { name: 'All / Active / Inactive', desc: 'Quick filters with their counts.' },
                    { name: 'Trash', desc: 'Shown when plans are in the trash: restore or delete for good.' },
                    { name: 'Delete selected', desc: 'Move several checked plans to the trash at once.' },
                    { name: '"No free trial" warning', desc: 'Shown above the table when no trial plan is active.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Name', desc: 'In the panel language; click to edit. Below: id, "trial", star if featured, badge, discount %.' },
                    { name: 'Code', desc: 'Stable plan identifier.' },
                    { name: 'Usage minutes', desc: 'Length of one usage window; "Unlimited" when 0.' },
                    { name: 'Price', desc: 'Base price and currency, with the original price under it when set.' },
                    { name: 'Duration', desc: 'Days until a subscription ends.' },
                    { name: 'Status', desc: 'Quick on/off toggle (needs "change status").' },
                    { name: '🌍 Manage prices', desc: 'Per-country prices; read-only without the update permission.' },
                    { name: '🗑️ Delete', desc: 'Moves the plan to the trash — see warnings.' },
                ],
            },
            {
                title: 'Plan form: language tabs',
                items: [
                    { name: 'One tab per language', desc: 'Like the countries form: red when that language has an error, green when complete.' },
                    { name: 'Name', desc: 'Required in every language.' },
                    { name: 'Badge', desc: 'Optional, e.g. "Most popular".' },
                    { name: 'Description', desc: 'Optional; shown on the pricing page.' },
                    { name: 'Features', desc: 'A separate bullet list per language, up to 12. Type and press Enter or "Add".' },
                ],
            },
            {
                title: 'Plan form: other fields',
                items: [
                    { name: 'Code', desc: 'Required and unique.' },
                    { name: 'Usage minutes', desc: 'When used up, the session closes. 0 = unlimited.' },
                    { name: 'Cooldown minutes', desc: 'Wait before a new window opens.' },
                    { name: 'Daily image limit', desc: 'Empty = unlimited, 0 = images not included.' },
                    { name: 'Daily videos & max seconds', desc: 'Either one = 0 means no video.' },
                    { name: 'Site projects & daily generations', desc: 'Either one = 0 means no site builder.' },
                    { name: 'Price, discount %, original price', desc: 'Fill any two; the third is calculated.' },
                    { name: 'Currency & duration', desc: 'Base price currency and subscription length.' },
                    { name: 'Sort order, featured, trial, status', desc: 'The first active trial plan in this order goes to new users.' },
                ],
            },
            {
                title: 'Manage prices',
                items: [
                    { name: 'One row per country', desc: 'Price, discount, original price; save each row.' },
                    { name: '"Base" / "Custom" badge', desc: 'Base = the plan price; Custom = its own price (remove it to fall back).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Set up the free trial (first launch)',
                steps: ['Click "Add plan".', 'In each language tab write the name (and description/features if you like).', 'Set the code, usage minutes (e.g. 30), cooldown and image/video/site limits.', 'Price 0, switch on "Trial" and "Status", save.'],
            },
            {
                title: 'Translate an existing plan',
                steps: ['Click the plan name.', 'Move between the language tabs and edit name, badge, description and features in each.', 'Save — the apps show each user their own language.'],
            },
            {
                title: 'A different price in Saudi Arabia',
                steps: ['Add the plan with its base price and currency; save.', 'Click 🌍 "Manage prices".', 'In the Saudi Arabia row enter the SAR price and save the row.'],
            },
            {
                title: 'Pause a plan, or restore it from the trash',
                steps: ['To pause: switch its status off — new subscribers no longer see it.', 'If deleted: open the "Trash" filter and click ↩️ restore.'],
            },
        ],
        affects: [
            { text: 'Plans and subscription page for users, providers and captains (DORR Driver AI) on Android, iOS and web — text arrives in the app’s language.' },
            { text: 'Assistant chat: the "minutes used up" message, cooldown time and plan chip.' },
            { text: 'Image, video and site generation open or close by plan limits.' },
            { route: 'admin.ai-subscriptions.index', text: 'AI subscriptions: every subscription points at a plan here.' },
            { route: 'admin.ai-usage-sessions.index', text: 'Usage sessions: minutes spent per plan.' },
            { route: 'admin.ai-trial-control.index', text: 'Free-trial control: who took the trial and its state.' },
            { text: 'Wallet notes and subscription notifications use the plan name.' },
        ],
        warnings: [
            { level: 'danger', text: 'With no active trial plan, every new user is locked out of the assistant. The screen warns above the table.' },
            { level: 'warning', text: 'Delete moves the plan to the trash: hidden from new subscribers and not renewed; current subscribers keep it until their period ends. Restore any time.' },
            { level: 'warning', text: 'Deleting for good from the trash is refused while the plan has subscriptions; the screen says why.' },
            { level: 'warning', text: 'Changing minutes or limits affects current subscribers too (limits are read live). Duration is fixed at subscription time.' },
            { level: 'info', text: 'The name is required in every active language. After adding a platform language, open each plan and write its text.' },
            { level: 'info', text: 'The free trial is one-time: once its minutes are used it becomes "ended" and does not reopen.' },
            { level: 'info', text: 'When a renewal charge fails, the subscription is suspended after a 3-day grace period.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-plans/index.vue (countries-style catalog list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-plans/ModalCreateAndUpdate.vue (language tabs: useCatalogTranslationFields)',
            'resources/js/modules/admin/themes/theme-1/views/ai-plans/ModalManagePrices.vue',
            'resources/js/composables/useAiPlans.js, stores/aiPlans.js',
            'Modules/AI/app/Http/Controllers/AiPlanController.php (CatalogController, group ai-plans)',
            'Modules/AI/app/Repositories/AiPlanRepository.php (TranslatableRepository; trash rules)',
            'Modules/AI/app/Models/AiPlan.php + AiPlanTranslation.php',
            'Modules/AI/app/Services/AiChatUsageGuard.php, AiMediaQuotaService.php, AiSubscriptionPurchaseService.php',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-plans            (?status_counts=1, search, trashed)',
            'GET|PUT|DELETE /api/admin/v1/ai-plans/{plan}',
            'PATCH          /api/admin/v1/ai-plans/{plan}/status',
            'POST           /api/admin/v1/ai-plans/delete-multiple',
            'POST           /api/admin/v1/ai-plans/{plan}/restore',
            'DELETE         /api/admin/v1/ai-plans/{plan}/force',
            'GET|POST       /api/admin/v1/ai-plans/{plan}/prices · DELETE …/prices/{price}',
            'Apps: GET /api/{user|provider|driver}/v1/ai-subscription/plans — same keys, text in X-Locale, is_active kept',
        ],
        tables: ['ai_plans (status, deleted_at)', 'ai_plan_translations (name, description, badge, features)', 'ai_plan_prices', 'ai_subscriptions.plan_id (restrict)'],
        permissions: [
            'ai-plans.view (screen, list, prices read-only)',
            'ai-plans.create · ai-plans.update (edit, prices, restore)',
            'ai-plans.delete (trash, force delete) · ai-plans.change-status · ai-plans.multiple-delete',
        ],
        decisions: [],
    },
};
