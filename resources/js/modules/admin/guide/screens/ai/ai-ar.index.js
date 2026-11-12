/**
 * Guide: DORR AR ops console (admin.ai-ar.index).
 * Source of truth checked: views/ai-ar/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-ar),
 * Http/Controllers/Ar/AiArAdminController.php, Http/Controllers/Ar/AiArController.php (info, enabled checks),
 * Services/Ar/AiArAssetService.php (upload vetting, versions, licence gate), Services/Ar/AiArOrderService.php
 * (escrow, commission, resolve, releaseDue), Services/Ar/AiArCapabilityService.php (device profiles),
 * Services/Ar/AiArCaptureService.php, Services/Ar/AiArExperienceService.php, Models/Ar/*.php.
 */
export default {
    route: 'admin.ai-ar.index',
    updated: '2026-11-11',

    ar: {
        summary: 'لوحة تشغيل محرك الواقع المعزز (AR): تشغيله وحدوده، ومكتبة النماذج ثلاثية الأبعاد وتراخيصها، ومتابعة مراجعات التصميم بين العميل والمزوّد، وحل نزاعات الأوردرات المدفوعة، وقايمة الأجهزة المختبرة.',
        audience: 'مدير المنتج أو فريق التشغيل والدعم. الفتح محتاج "الواقع المعزز: عرض"، وأي حفظ أو رفع أو قرار نزاع محتاج "تعديل".',
        purpose: [
            'تشغيل أو إيقاف الواقع المعزز للعملاء في تطبيق اليوزر ومقدم الخدمة.',
            'تحديد أقصى حجم وتعقيد للنماذج، ومدة الاحتفاظ باللقطات، وعمولة المنصة ومدة الضمان.',
            'تجهيز مكتبة نماذج عامة (أثاث مثلًا) بترخيص واضح.',
            'الحكم في نزاع بين عميل ومزوّد: تحرير الفلوس للمزوّد أو استرجاعها للعميل.',
            'تحديد الأجهزة اللي فعلًا بتدعم AR كامل أو أساسي أو عارض 3D بس.',
        ],
        sections: [
            {
                title: 'تاب الإعدادات',
                items: [
                    { name: 'محرك الواقع المعزز مفعّل', desc: 'لو مقفول: التطبيق بيقول إن الخدمة متوقفة، ومحدش يقدر يعمل تجربة AR جديدة.' },
                    { name: 'أقصى حجم للنموذج (ميجا)', desc: 'من 1 لـ 100. أي ملف GLB أكبر بيترفض وقت الرفع (للأدمن وللعميل).' },
                    { name: 'أقصى عدد مثلثات للنموذج', desc: 'من 1000 لـ 2,000,000. نموذج أعقد من كده بيترفض. القيمتين دول بيوصلوا للتطبيق كحدود.' },
                    { name: 'مدة الاحتفاظ باللقطات (أيام)', desc: 'من 1 لـ 365. اللقطات اللي العميل بيصورها بتتمسح بعد المدة دي. التعديل بيسري على اللقطات الجديدة بس.' },
                    { name: 'عمولة المنصة (%)', desc: 'من 0 لـ 50. بتتخصم من المزوّد، وبتتثبت على كل أوردر وقت الدفع، فتغييرها مش بيأثر على الأوردرات المفتوحة.' },
                    { name: 'أيام تحرير المبلغ بعد التسليم', desc: 'من 1 لـ 30. بعد ما المزوّد يسلّم، العميل عنده المدة دي يأكد أو يعترض. لو ما عملش حاجة الفلوس بتتحرر للمزوّد تلقائيًا.' },
                    { name: 'حفظ', desc: 'يحفظ كل الإعدادات مرة واحدة.' },
                ],
            },
            {
                title: 'تاب مكتبة النماذج',
                items: [
                    { name: 'إضافة نموذج (.glb)', desc: 'الاسم بالإنجليزي (إجباري) وبالعربي، والتصنيف (افتراضي furniture)، والترخيص، والملف. الملف بيتفحص: لازم يبقى GLB حقيقي وفي حدود الحجم والمثلثات. النموذج بيتسجل "مسودة" ومش عام.' },
                    { name: 'الترخيص', desc: 'own / cc0 / cc_by / purchased / generated. نموذج المكتبة من غير ترخيص مينفعش يتفعّل ولا يبقى عام.' },
                    { name: 'الحالة', desc: 'draft (مسودة) أو active (فعّال) أو rejected (مرفوض).' },
                    { name: 'النسخة الفعّالة', desc: 'اختيار نسخة بيخليها هي اللي التجارب بتحمّلها، وبيخلي النموذج "فعّال". رفع نسخة جديدة مش بيغيّر اللي شغال لحد ما تختارها هنا.' },
                    { name: 'عام', desc: 'يظهر لكل العملاء في المكتبة. محتاج النموذج يكون فعّال وعنده ترخيص.' },
                    { name: 'رفض', desc: 'يخلي النموذج "مرفوض" ويشيله من العام. مش بيمسح الملف.' },
                ],
            },
            {
                title: 'تاب مراجعات التصميم (قراءة بس)',
                items: [
                    { name: 'التصميم', desc: 'اسم تجربة الـ AR ونوعها.' },
                    { name: 'المزوّد', desc: 'رقم مقدم الخدمة اللي عامل التصميم.' },
                    { name: 'الحالة', desc: 'في الانتظار / تمت الموافقة / مطلوب تعديلات / مرفوض / تم السحب.' },
                    { name: 'ملاحظة العميل وملاحظة المزوّد والتاريخ', desc: 'آخر 200 مراجعة.' },
                ],
            },
            {
                title: 'تاب الأوردرات',
                items: [
                    { name: 'الفلتر', desc: 'بيفتح على "نزاع". الحالات: مدفوع، تم التسليم، نزاع، تم التحرير، تم الاسترجاع.' },
                    { name: 'المبلغ والعمولة', desc: 'المبلغ اللي العميل دفعه، والعمولة ونسبتها المثبتة على الأوردر. بيتعرضوا بعدد الخانات العشرية الحقيقي لعملة الأوردر (مثلًا 3 خانات للدينار الكويتي، 2 للجنيه).' },
                    { name: 'سبب النزاع', desc: 'اللي العميل كتبه وهو بيعترض.' },
                    { name: 'سبب القرار + تحرير للمزوّد / استرجاع للعميل', desc: 'بيظهروا للأوردر اللي عليه نزاع بس. السبب إجباري (3 لـ 500 حرف). التحرير: المزوّد بياخد المبلغ ناقص العمولة في رصيد قابل للسحب، والعمولة بتتسجل إيراد للمنصة. الاسترجاع: العميل بياخد المبلغ كامل في رصيد للصرف بس.' },
                ],
            },
            {
                title: 'تاب الأجهزة المختبرة',
                items: [
                    { name: 'النظام', desc: 'أندرويد أو iOS (الافتراضي أندرويد). الجهاز بيتقارن بس بالصفوف اللي من نفس نظامه، والتطبيق بيتعرف نظامه من هيدر X-Platform اللي بيبعته مع كل طلب.' },
                    { name: 'الشركة المصنعة', desc: 'زي Samsung. المقارنة مش حساسة لحروف كبيرة وصغيرة.' },
                    { name: 'الموديل', desc: 'نمط بـ % كبديل، مثلًا SM-S91% يغطي كل موديلات S23.' },
                    { name: 'المستوى + ARCore', desc: 'واقع معزز كامل / أساسي / عارض 3D بس. الجهاز اللي بيطابق صف هنا مستواه مش بيعدّي المستوى ده، ولو ARCore مش مدعوم بيتعامل كعارض 3D بس.' },
                    { name: 'حذف', desc: 'يشيل الصف فورًا (من غير تأكيد).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تجهيز المكتبة بنموذج أثاث عام',
                steps: [
                    'في "مكتبة النماذج" اكتب الاسم بالإنجليزي والعربي والتصنيف.',
                    'اختار الترخيص وارفع ملف .glb ودوس رفع.',
                    'من "النسخة الفعّالة" اختار v1 علشان النموذج يبقى فعّال.',
                    'فعّل "عام" علشان يظهر لكل العملاء.',
                ],
            },
            {
                title: 'حل نزاع على أوردر',
                steps: [
                    'افتح تاب "الأوردرات" (بيفتح على "نزاع").',
                    'اقرا سبب النزاع، وراجع ملاحظات المراجعة في "مراجعات التصميم".',
                    'اكتب سبب قرارك ودوس "تحرير للمزوّد" أو "استرجاع للعميل".',
                ],
            },
            {
                title: 'جهاز بيقول إنه بيدعم AR وهو مش شغال كويس',
                steps: [
                    'في "الأجهزة المختبرة" اكتب الشركة والموديل (بـ % لو عايز تغطي عيلة كاملة).',
                    'اختار المستوى "عارض ثلاثي الأبعاد فقط" أو "أساسي"، ودوس حفظ.',
                ],
            },
        ],
        affects: [
            { text: 'تطبيق اليوزر ومقدم الخدمة: تشغيل الـ AR، وحدود رفع النماذج، والمكتبة العامة، ومستوى الـ AR لكل جهاز.' },
            { text: 'محفظة العميل والمزوّد: قرار النزاع بيحوّل الفلوس فعلًا، والعمولة بتتسجل في السجل المالي للمنصة.' },
            { text: 'إشعارات للعميل والمزوّد لما الأوردر يتحرر أو يترجّع.' },
        ],
        warnings: [
            { level: 'danger', text: 'قرار النزاع نهائي ومينفعش يترجع فيه: الفلوس بتتحول فورًا للمزوّد أو للعميل.' },
            { level: 'warning', text: 'رفض نموذج بيشيله من المكتبة العامة، والتجارب اللي كانت بتستخدمه ممكن ما تلاقيهوش.' },
            { level: 'info', text: 'تغيير العمولة مش بيأثر على الأوردرات اللي اتدفعت قبل كده، لأن النسبة بتتثبت وقت الدفع.' },
            { level: 'info', text: 'الأوردرات اللي اتسلّمت وعدّت مدة التحرير من غير اعتراض بتتحرر للمزوّد لوحدها بالجدولة.' },
            { level: 'info', text: 'الجداول بتعرض آخر 200 صف بس.' },
            { level: 'info', text: 'لو معاك صلاحية العرض بس: الإعدادات والقوايم بتبقى للقراءة، وفورمات الإضافة وأزرار الرفض والحذف وقرارات النزاع مش بتظهر.' },
        ],
    },

    en: {
        summary: 'Ops console for the AR engine: on/off and limits, the 3D model library and its licences, oversight of design reviews between customer and provider, disputes on paid orders, and the tested-device list.',
        audience: 'Product owner or the ops/support team. Opening needs "AR: view"; any save, upload or dispute decision needs "update".',
        purpose: [
            'Turn AR on or off for customers in the user and provider apps.',
            'Set model size/complexity limits, capture retention, platform commission and escrow time.',
            'Build a public model library (furniture, for example) with clear licences.',
            'Decide a customer–provider dispute: release to the provider or refund the customer.',
            'Say which devices really get full AR, basic AR or only the 3D viewer.',
        ],
        sections: [
            {
                title: 'Settings tab',
                items: [
                    { name: 'AR engine enabled', desc: 'Off: the app reports the service as off and no new AR experience can be created.' },
                    { name: 'Max model size (MB)', desc: '1–100. Bigger GLB files are refused on upload (admin and customer).' },
                    { name: 'Max triangles per model', desc: '1,000–2,000,000. More complex models are refused. Both limits are sent to the app.' },
                    { name: 'Keep captures (days)', desc: '1–365. Customer captures are deleted after this; applies to new captures.' },
                    { name: 'Platform commission (%)', desc: '0–50. Taken from the provider and fixed on each order when paid, so open orders never change.' },
                    { name: 'Days until release after delivery', desc: '1–30. After delivery the customer has this long to confirm or dispute; otherwise the provider is paid automatically.' },
                    { name: 'Save', desc: 'Saves all settings at once.' },
                ],
            },
            {
                title: 'Model library tab',
                items: [
                    { name: 'Add a model (.glb)', desc: 'English name (required), Arabic name, category (default furniture), licence and file. The file is vetted: a real GLB within the size and triangle limits. It is saved as a draft, not public.' },
                    { name: 'Licence', desc: 'own / cc0 / cc_by / purchased / generated. A library model without a licence cannot be activated or made public.' },
                    { name: 'Status', desc: 'draft, active or rejected.' },
                    { name: 'Active version', desc: 'Picking a version makes it the one experiences load and marks the model active. A new upload never replaces what is live until you pick it here.' },
                    { name: 'Public', desc: 'Visible to every customer in the library. Needs an active model with a licence.' },
                    { name: 'Reject', desc: 'Marks the model rejected and removes it from public. The file is kept.' },
                ],
            },
            {
                title: 'Design reviews tab (read-only)',
                items: [
                    { name: 'Design', desc: 'AR experience title and type.' },
                    { name: 'Provider', desc: 'Id of the provider who made it.' },
                    { name: 'Status', desc: 'Waiting / approved / changes requested / rejected / withdrawn.' },
                    { name: 'Customer note, provider note, date', desc: 'Latest 200 reviews.' },
                ],
            },
            {
                title: 'Orders tab',
                items: [
                    { name: 'Filter', desc: 'Opens on "Disputed". Statuses: paid, delivered, disputed, released, refunded.' },
                    { name: 'Amount & commission', desc: 'What the customer paid, plus the commission and the percent fixed on the order. Shown with the order currency’s real decimal places (e.g. 3 for KWD, 2 for EGP).' },
                    { name: 'Dispute reason', desc: 'What the customer wrote when objecting.' },
                    { name: 'Reason + Release / Refund', desc: 'Only on disputed orders. The reason is required (3–500 chars). Release: the provider gets the amount minus commission as withdrawable balance; the commission is booked as platform revenue. Refund: the customer gets the full amount as spend-only balance.' },
                ],
            },
            {
                title: 'Tested devices tab',
                items: [
                    { name: 'Platform', desc: 'Android or iOS (default Android). A device is only matched against rows of its own platform; the app’s platform comes from the X-Platform header it sends with every request.' },
                    { name: 'Manufacturer', desc: 'E.g. Samsung; case-insensitive.' },
                    { name: 'Model', desc: 'A pattern with % as wildcard, e.g. SM-S91% for the whole S23 family.' },
                    { name: 'Tier + ARCore', desc: 'Full AR / basic AR / 3D viewer only. A matching device never gets more than this tier; without ARCore it falls back to the 3D viewer.' },
                    { name: 'Delete', desc: 'Removes the row immediately (no confirmation).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Add a public furniture model',
                steps: ['In "Model library" enter the English and Arabic names and the category.', 'Pick the licence, choose the .glb file and click Upload.', 'In "Active version" pick v1 so the model becomes active.', 'Switch on "Public" so every customer sees it.'],
            },
            {
                title: 'Resolve an order dispute',
                steps: ['Open the "Orders" tab (it opens on "Disputed").', 'Read the dispute reason and check the review notes in "Design reviews".', 'Write your reason and click "Release to provider" or "Refund customer".'],
            },
            {
                title: 'A device claims AR but performs badly',
                steps: ['In "Tested devices" enter the manufacturer and model (use % for a family).', 'Choose "3D viewer only" or "Basic AR" and save.'],
            },
        ],
        affects: [
            { text: 'User and provider apps: AR on/off, upload limits, the public library and the AR tier per device.' },
            { text: 'Customer and provider wallets: a dispute decision really moves money; the commission is booked in the platform ledger.' },
            { text: 'Notifications to the customer and provider when an order is released or refunded.' },
        ],
        warnings: [
            { level: 'danger', text: 'A dispute decision is final: the money moves immediately to the provider or the customer.' },
            { level: 'warning', text: 'Rejecting a model removes it from the public library; experiences using it may no longer find it.' },
            { level: 'info', text: 'Changing the commission does not affect orders already paid — the percent is fixed at payment.' },
            { level: 'info', text: 'Delivered orders past the release window without a dispute are paid out automatically by the scheduler.' },
            { level: 'info', text: 'Tables show the latest 200 rows only.' },
            { level: 'info', text: 'With view only: settings and pickers are read-only; add forms, reject/delete buttons and dispute decisions are hidden.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-ar/index.vue',
            'Modules/AI/app/Http/Controllers/Ar/AiArAdminController.php',
            'Modules/AI/app/Services/Ar/AiArAssetService.php, AiArOrderService.php, AiArCapabilityService.php, AiArCaptureService.php',
            'Modules/AI/app/Models/Ar/AiArSetting.php, AiArAsset.php, AiArAssetVersion.php, AiArApproval.php, AiArOrder.php, AiArDeviceProfile.php',
        ],
        api: [
            'GET|PUT /api/admin/v1/ai-ar/settings',
            'GET|POST /api/admin/v1/ai-ar/assets · PUT …/assets/{id} · POST …/assets/{id}/versions|activate|publish|reject',
            'GET  /api/admin/v1/ai-ar/reviews',
            'GET  /api/admin/v1/ai-ar/orders (?status; top-level currency_decimals {code: places}) · POST …/orders/{id}/resolve (release|refund, note)',
            'GET|POST /api/admin/v1/ai-ar/devices (platform android|ios) · DELETE …/devices/{id}',
            'Apps: /api/{user|provider}/v1/ai-ar/* (info: enabled + limits, capability, assets)',
        ],
        tables: ['ai_ar_settings', 'ai_ar_assets', 'ai_ar_asset_versions', 'ai_ar_approvals', 'ai_ar_orders', 'ai_ar_device_profiles (platform default android, model_pattern = SQL LIKE)'],
        permissions: [
            'ai-ar.view (screen, every list)',
            'ai-ar.update (settings, storeAsset, asset actions, resolveOrder, storeDevice, destroyDevice — the middleware maps these method names to update)',
        ],
        decisions: [
            {
                ar: 'رفع نسخة جديدة لنموذج موجود (POST assets/{id}/versions) موجود في الـ API بس مفيش له زرار في الشاشة.',
                en: 'Uploading a new version of an existing model (POST assets/{id}/versions) exists in the API but has no button on the screen.',
            },
        ],
    },
};
