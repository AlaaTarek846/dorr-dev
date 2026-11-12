/**
 * Guide: freelance special offers (admin.freelance.specialOffers) — read only.
 * Source of truth checked: views/freelance/special-offers/index.vue, Modules/Freelance/routes/admin.php (freelance-special-offers),
 * Modules/Freelance/app/Http/Controllers/Admin/FreelanceSpecialOfferAdminController.php (index, show), Models/FreelanceSpecialOffer.php (STATUSES, KINDS),
 * locales freelance.specialOffers.
 */
export default {
    route: 'admin.freelance.specialOffers',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل للقراءة بس للعروض الخاصة اللي المحترفين بيبعتوها لعملاء اشتغلوا معاهم قبل كده: شغل مخصوص، أو مستوى خدمة بسعر أقل، أو استشارة بخصم.',
        audience: 'فريق مراقبة الجودة والثقة في الشغل الحر. محتاج صلاحية "العروض الخاصة: عرض" بس، ومفيش أي زرار بيعدّل.',
        purpose: [
            'تعرف مين عرض على مين، بكام، والنتيجة إيه.',
            'تلاحظ محترف بيضغط على العملاء بعروض كتير أو مرفوضة كتير.',
        ],
        sections: [
            {
                title: 'كروت الأرقام (فوق)',
                items: [
                    { name: 'اتبعت / اتقبل / اترفض / اتسحب / انتهى', desc: 'عدد العروض في كل حالة (بعد فلتر النوع والبحث). الضغط على كارت بيفلتر الجدول بالحالة دي.' },
                ],
            },
            {
                title: 'الفلاتر',
                items: [
                    { name: 'الحالة', desc: 'الكل أو حالة واحدة، وجنب كل واحدة العدد.' },
                    { name: 'النوع', desc: 'مخصوص (custom) أو خدمة (service) أو استشارة (consultation).' },
                    { name: 'البحث', desc: 'بعنوان العرض أو اسم/رقم العميل أو المحترف (لحد 100 حرف).' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'العرض', desc: 'العنوان ونوعه.' },
                    { name: 'المحترف / العميل', desc: 'الاسم، ورقم موبايل العميل.' },
                    { name: 'السعر', desc: 'سعر العرض بعملته، والسعر العادي مشطوب لو موجود. الاستشارة بتظهر كنسبة خصم.' },
                    { name: 'الحالة', desc: 'شارة الحالة، و"شافه" لو العميل فتح العرض.' },
                    { name: 'اتبعت', desc: 'تاريخ ووقت الإرسال.' },
                    { name: 'فتح', desc: 'نافذة التفاصيل.' },
                ],
            },
            {
                title: 'نافذة العرض',
                items: [
                    { name: 'الوصف والرسالة', desc: 'اللي المحترف كتبه للعميل.' },
                    { name: 'الخدمة', desc: 'لو العرض على خدمة: اسمها والمستوى والباقة.' },
                    { name: 'الفلوس', desc: 'للاستشارة: كل مدة وسعرها قبل وبعد الخصم. لغير كده: السعر، السعر العادي، رسوم العميل، عمولة المنصة، صافي المحترف، ومدة التسليم بالأيام.' },
                    { name: 'التسلسل', desc: 'اتبعت، اتشاف، اترد عليه، وسبب الرفض لو العميل كتبه.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'مراجعة محترف بيضغط على العملاء',
                steps: [
                    'اكتب اسم المحترف في البحث.',
                    'دوس كارت "اترفض" وشوف عدد العروض المرفوضة وأسباب الرفض.',
                    'لو فيه إساءة، الإجراء بيتاخد من شاشة الجزاءات.',
                ],
            },
        ],
        affects: [
            { route: 'admin.freelance.policies', text: 'حدود العروض الخاصة بتتظبط في الأسعار والقواعد ← العروض الخاصة، مش هنا.' },
            { route: 'admin.freelance.sanctions', text: 'أي إجراء على محترف بيتاخد من الجزاءات.' },
        ],
        warnings: [
            { level: 'info', text: 'الشاشة للقراءة بس: مفيش تعديل ولا إلغاء لعرض من هنا.' },
            { level: 'info', text: 'المبالغ بتتخزن بأصغر وحدة للعملة، والشاشة بتقسمها على عدد الكسور العشرية للعملة.' },
        ],
    },

    en: {
        summary: 'A read-only log of the special offers freelancers send to clients they already worked with: custom work, a service level at a lower price, or a discounted consultation.',
        audience: 'The freelance quality and trust team. Needs only "Special offers: view"; nothing here edits.',
        purpose: [
            'See who offered whom what, at which price, and what came of it.',
            'Spot a freelancer pressing clients with many (or many declined) offers.',
        ],
        sections: [
            {
                title: 'Counters (top)',
                items: [
                    { name: 'Sent / Accepted / Declined / Withdrawn / Expired', desc: 'Offers per status (after the kind filter and search). Clicking one filters the table.' },
                ],
            },
            {
                title: 'Filters',
                items: [
                    { name: 'Status', desc: 'All or one status, each with its count.' },
                    { name: 'Kind', desc: 'Custom, service or consultation.' },
                    { name: 'Search', desc: 'Offer title, or the client\'s / freelancer\'s name or phone (up to 100 characters).' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Offer', desc: 'Title and kind.' },
                    { name: 'Freelancer / Client', desc: 'Name, and the client\'s phone.' },
                    { name: 'Price', desc: 'The offer price in its currency, the usual price struck through when known. A consultation shows its discount.' },
                    { name: 'State', desc: 'Status badge, and "seen" once the client opened it.' },
                    { name: 'Sent', desc: 'When it was sent.' },
                    { name: 'Open', desc: 'The details window.' },
                ],
            },
            {
                title: 'Offer window',
                items: [
                    { name: 'Description and message', desc: 'What the freelancer wrote to the client.' },
                    { name: 'Service', desc: 'For a service offer: the service, level and tier.' },
                    { name: 'Money', desc: 'Consultation: every length with its price before and after the discount. Others: price, usual price, client fee, platform commission, freelancer net, delivery days.' },
                    { name: 'Timeline', desc: 'Sent, seen, answered, and the decline reason if the client gave one.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Check a freelancer who presses clients',
                steps: [
                    'Type the freelancer\'s name in the search.',
                    'Click "Declined" and read the declined offers and their reasons.',
                    'Any action is taken from the sanctions screen.',
                ],
            },
        ],
        affects: [
            { route: 'admin.freelance.policies', text: 'The special-offer limits are set in Prices & rules → Special offers, not here.' },
            { route: 'admin.freelance.sanctions', text: 'Any action on a freelancer is taken from Sanctions.' },
        ],
        warnings: [
            { level: 'info', text: 'Read only: no offer can be edited or cancelled here.' },
            { level: 'info', text: 'Amounts are stored in the currency\'s minor unit; the screen divides by the currency\'s decimals.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/freelance/special-offers/index.vue',
            'Modules/Freelance/app/Http/Controllers/Admin/FreelanceSpecialOfferAdminController.php',
            'Modules/Freelance/app/Models/FreelanceSpecialOffer.php (STATUSES, KINDS)', 'Modules/Freelance/app/Services/Offers/SpecialOffers.php (present)',
        ],
        api: [
            'GET /api/admin/v1/freelance-special-offers   (?status, kind, search, page, per_page) + counts per status',
            'GET /api/admin/v1/freelance-special-offers/{uuid}',
        ],
        tables: ['freelance_special_offers'],
        permissions: ['freelance-special-offers.view (index, show)'],
        decisions: [],
    },
};
