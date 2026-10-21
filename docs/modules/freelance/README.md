# Freelance (العمل الحر) — `Modules/Freelance`

> الملف ده بيتحدّث مع كل مهمة من [docs/freelance_tasks.md](../../freelance_tasks.md).

## المبني لحد دلوقتي

- **السحب للمحترف + محفظة التطبيق (FL-005 / 7ب):** بقرار المالك (2026-10-20) مسارات سحب السائق بقت شغالة لـ `freelance_api` (وجهات بالـ PIN، طلب بيحجز المبلغ، الأدمن بيوافق بإيصال أو يرفض بسبب، إيصال التحميل). التحويل بين المستخدمين لسه مقفول. شاشات التطبيق: بوابة الـ PIN والبصمة والرصيد والكشف والشحن والسحب (تفاصيلها في [APP.md](APP.md)). اختبار: `tests/Feature/FreelancerWalletTest.php`.

- **FL-107 / FL-108 / FL-110 (شاشات التطبيق):** التوثيق في `freelanceApp/` — رفع المستندات (معرض/ملف/كاميرا)، التقديم للمراجعة، عرض ملفاتي بالتوكن، وبوابة التوثيق المشتركة (`Gate`). التفاصيل في [APP.md](APP.md).

- **FL-104 (شاشات التطبيق):** التخصصات والمهارات والخبرات والشهادات في `freelanceApp/` (تفاصيلها في [APP.md](APP.md)). اتجرّبت على موبايل حقيقي، واتصلّح فيها قتل النظام للتطبيق وقت المنتقي.

- **FL-1207 (الخطوة 1):** تطبيق المحترف `freelanceApp/` («دُرر للمحترفين»، `com.dorr.freelance`) — مشروع منفصل على `:core` المشترك مع `driverApp`، وشكله منسوخ من `androidApp` (الثيم والعُدّة والحقول والسبلاش وشاشة مفيش إنترنت والعربي/الإنجليزي). التفاصيل والخطوات الجاية: [APP.md](APP.md).

- **FL-105 (الـ API):** معرض أعمال المحترف: حتى 30 عمل بصور (حتى 10، تخزين **خاص**)، رابط فيديو يوتيوب أو فيميو بس (بيتحوّل لعنوان تضمين ثابت)، ورابط خارجي http/https. الخصوصية `public` / `clients_only` / `private`، وأي عمل بيتعرض لغير صاحبه لازم عليه إقرار حقوق (`rights_confirmed`). الغير بيشوف أعمال المحترف **الموثّق** بس، والصور بروابط موقّعة بتنتهي (30 دقيقة) ومتراجعة عند كل فتح. الأدمن بيخفي عمل مخالف بسبب والمحترف بيتبلّغ. الاختبار: `tests/Feature/FreelancerPortfolioTest.php`.

- **FL-107 / FL-108 / FL-110:** التوثيق اليدوي والبوابة. حالات الحساب: `registered` ← `profile_ready` ← `verification_submitted` ← `verified` / `verification_rejected` / `changes_requested`. الأدمن بيحدد أنواع مستندات الهوية والمطلوب منها في كل دولة (مواطن / مقيم / الكل)، والمحترف بيرفعها (تخزين خاص) ويقدّم طلب، والأدمن يعتمد أو يرفض أو يطلب تعديل بسبب. انتهاء المستند يومياً بيرجّع الموثّق لـ `changes_requested`. **البوابة:** أي route بتشتغل (عرض، قبول دعوة، تمويل، استلام) لازم عليها `freelance.verified`، أو `app(FreelancerGate::class)->assertCanApply($freelancer)` جوه الخدمة. الاختبار: `tests/Feature/FreelancerVerificationTest.php`.

- **FL-104 (الـ API):** تخصصات ومهارات وخبرات وشهادات المحترف تحت `/api/freelance/v1/profile/*`. التخصصات حتى 5 وواحد أساسي، والمهارات حتى 30 بمستوى (`beginner` لـ `expert`) و`usage_count` بيتحرك، والدمج بينقل صفوف الملف من غير تكرار، والمهارة المقترحة المعلّقة تتحط في الملف وتتشال لو اترفضت. الخبرات والشهادات (حتى 20 لكل واحدة) مع إثبات الشهادة (صورة أو PDF حتى 5MB) في التخزين **الخاص** ومالوش رابط عام، وصاحبه بس يحمّله. حذف الحساب بيمسح القوائم والملفات. الاختبار: `tests/Feature/FreelancerProfileListsTest.php`.

- **FL-103 (الـ API):** ملف المحترف: `headline` و`bio` و`years_of_experience` و`contact_languages` و`city` عن طريق `PUT profile`، والبريد بالكود (`profile/email/send-code` ثم `profile/email/confirm`) ومش من `PUT profile`. `completeness` (`percent` و`missing` و`steps`) في كل رد عن الحساب، وهي بتقيس بس ومش بتغيّر حالة الحساب. العملة من الدولة (مفيش حقل). شاشات التطبيق مؤجّلة لـ `freelanceApp/` (FL-1207). الاختبار: `tests/Feature/FreelancerProfileTest.php`.

- **FL-102:** كتالوج المهارات: `freelance_skills` + ترجماتها (مع اسم منظّف `normalized` يمنع التكرار بين الحروف الكبيرة والصغيرة والتشكيل وأشكال الألف) + ربطها بالتخصصات. المحترف يقترح مهارة جديدة (`pending` ومش بتظهر لغيره) والأدمن يوافق (الاسم بالعربي والإنجليزي إجباري) أو يرفض بسبب أو **يدمجها في مهارة موجودة** (بتفضل اسم بديل بيوصّل للمهارة الباقية، والحدث `FreelanceSkillsMerged` عشان مهارات المحترف والمشاريع بعدين تنقل صفوفها). السقف: 5 اقتراحات جديدة في اليوم. الاختبار: `tests/Feature/FreelanceSkillsTest.php`.

- **FL-101:** كتالوج التخصصات: `freelance_specialties` (تخصص رئيسي وتحته تخصصات فرعية، **مستويين بس**، `parent_id` و`status` و`sort_order`) و`freelance_specialty_translations` (الاسم والوصف بكل لغة). القواعد في `FreelanceSpecialties` (الأب لازم يكون رئيسي، ومينفعش يبقى أب نفسه، والحذف مرفوض لو عنده فروع). الأدمن: `admin/v1/freelance-specialties` (CRUD + `status` + `reorder` + `delete-multiple`) بصلاحية `freelance-catalogs.*` (ومعاها `multiple-delete`)، وصفحة «التخصصات» في الأدمن. شاشة «الأسعار والقواعد» بقت تعرض التخصصات في اختيار النطاق. `FreelanceSpecialtySeeder` بيزرع كتالوج ابتدائي (7 تخصصات رئيسية وفروعها، يتكرر بدون تكرار ويسيب اللي الأدمن عدّله). الاختبار: `tests/Feature/FreelanceSpecialtiesTest.php`. قراءة التطبيقات للتخصصات ضمن FL-104.

- **FL-009:** الفئات المالية في دفتر المنصة: `freelance_commissions` و`freelance_client_fees` (دخل) و`freelance_discounts` (مصروف)، بتتزرع بالـ migration. للتسجيل: `app(FreelanceLedger::class)->commission($amountMinor, $currency, $country, $milestone, $projectTitle)` (وكذلك `clientFee` و`discount`)، والصفر بيتخطى. الاختبار: `tests/Feature/FreelanceLedgerTest.php`.

- **FL-008:** الأسعار والقواعد: `freelance_policies` (دولة، أو دولة + تخصص) فوق `freelance.policy_defaults`، والأدق بيغلب مفتاح بمفتاح. المبالغ بالوحدة الصغرى لعملة الدولة (مفيش مفتاح عملة)، والنسب بالـ basis points. القراءة: `app(FreelancePolicies::class)->for($countryId, $specialtyId)`، والحساب: `FreelanceFees::breakdown($amountMinor, $policy)` (رسم العميل، إجمالي العميل، العمولة، صافي المحترف)، واللقطة للاتفاق: `->snapshot($country->currency)`. شاشة الأدمن «الأسعار والقواعد» فيها معاينة لحظية. الاختبار: `tests/Feature/FreelancePoliciesTest.php`.

- **FL-007:** إشعارات المحترف: نفس endpoints المستخدم والسائق (`/api/freelance/v1/notifications/*` والأجهزة)، وقناته الخاصة `Modules.Freelance.Models.Freelancer.{id}`، و`GET freelance/v1/realtime-config`، وOneSignal خاص بالتطبيق (اختياري). `FreelanceNotifier::notify($receiver, 'offer.accepted', [...vars], [...data])` هو الباب الوحيد لإشعارات العمل الحر (الكتالوج في `FreelanceNotifier::EVENTS`، والنصوص في `lang/*/notifications.php`). الاختبار: `tests/Feature/FreelanceNotificationsTest.php`.

- **FL-006:** شات خاص بالعمل الحر جوه الموديول (مش موديول Chat): `freelance_conversations` و`freelance_messages`، غرفة لكل (عميل + محترف + سياق). الغرف بيفتحها السيرفر بس (`FreelanceChatService::open($client, $freelancer, 'project', $id)` من خدمات المشاريع في الدفعة 2). الـ API: للمحترف `/api/freelance/v1/chat/*` وللعميل `/api/mobile/v1/freelance/chat/*`، لحظي على قناة `freelance-chat.{uuid}`. الاختبار: `tests/Feature/FreelanceChatTest.php`.

- **FL-005:** محفظة المحترف: المحترف مالك محفظة (alias `freelancer` في `wallet.morph_map`، محفظة لكل دولة، الرصيد صفر)، و`/api/freelance/v1/wallet/*` بنفس شاشات المستخدم (الرصيد، الكشف، الـ PIN، **الشحن بالبوابات**، الـ checkouts والكوبونات). السحب والتحويل مقفولين. الأدمن بيشوف محافظ المحترفين ويفلتر عليها. الاختبار: `tests/Feature/FreelancerWalletTest.php`.

- **FL-004:** عقد الـ API الموحد: بادئة المحترف `/api/freelance/v1` وبادئة العميل `/api/mobile/v1/freelance`، و`GET config` للاتنين (مرة واحدة عند فتح التطبيق)، وmiddleware `freelance.feature:<مفتاح>` لحماية أي route بمفتاح من FL-002. القواعد كلها وأكواد الأخطاء وجداول الـ endpoints في [API.md](API.md). الاختبار: `tests/Feature/FreelanceApiContractTest.php`.

- **FL-003:** هوية المحترف المستقلة.
  - **الجداول:** `freelancers` و`freelancer_devices`، والحارس `freelance_api`.
  - **الحالات:** `incomplete` (دخل بالموبايل بس) ← `pending_verification` (كمّل الاسم ووافق على الشروط) ← `active` (بعد التوثيق، FL-110). والأدمن يقدر يحط `suspended` (يدخل ويشوف السبب) أو `blocked` (ما يدخلش وكل جلساته بتتقفل).
  - **الجلسات:** جلسة لكل جهاز (`device_id`)، عشان التطبيق والموقع والداشبورد يشتغلوا مع بعض. نفس الجهاز لو دخل تاني بيقفل جلسته القديمة بس.
  - **حذف الحساب:** soft delete مع مسح البيانات الشخصية، والرقم يتحرر للتسجيل من جديد.
  - **الـ endpoints:**

    | الطريق | الوظيفة |
    |---|---|
    | `POST freelance/v1/auth/otp` · `auth/verify` · `auth/resend` | الدخول بالـ OTP (`verify` بياخد `device_id` و`device_name` و`platform`) |
    | `GET auth/me` · `POST auth/logout` | بياناتي (فيها `missing` و`terms`) والخروج |
    | `PUT profile` · `POST profile/avatar` | إكمال البيانات والصورة |
    | `POST terms/accept` | `{version}` لازم تساوي `freelance.terms_version` |
    | `GET devices` · `DELETE devices/{id}` | أجهزتي وإنهاء جلسة |
    | `DELETE account` | `{confirm: true}` |
    | `GET admin/v1/freelancers` · `GET admin/v1/freelancers/{id}` · `POST admin/v1/freelancers/{id}/status` | إدارة مبدئية (الشاشة الكاملة في FL-801) |

  - **الأخطاء:** `freelance_not_available` (403، الخدمة مقفولة في الدولة)، `freelance_terms_outdated` (422).
  - **الاختبار:** `tests/Feature/FreelancerAuthTest.php`.

- **FL-002:** مفاتيح الخصائص لكل دولة (بدون محافظات): جدول `freelance_feature_rules`، `FreelanceFeatures` (مفيش قاعدة = مقفول، وقفل `freelance` يقفل الكل)، وشاشة «مفاتيح الخصائص» في الأدمن مع نسخ من دولة أخرى. المفاتيح الحالية في `FreelanceFeatures::FEATURES` وكل دفعة تضيف مفاتيحها. الاستخدام: `app(FreelanceFeatures::class)->allows($countryId, 'bidding')`. الاختبار: `tests/Feature/FreelanceFeaturesTest.php`. زر «تنزيل الشرح (Word)» في الشاشة بيولّد ملف عربي من `FreelanceFeaturesGuide` (لما تضيف مفتاح جديد اكتب وصفه في `MEANINGS` هناك).

- **FL-001:** الموديول `Modules/Freelance` (provider + routes API فاضية + config)، مسجّل في `modules_statuses.json`. خدمة «العمل الحر» (`module_name = freelance`، للأدمن والمستخدم، من غير `requires_provider` لأن المحترف هوية مستقلة) في `ServiceCategoriesSeeder` + migration تضيفها للقواعد القديمة. مجموعات الصلاحيات: `freelance-settings` و`freelancers` و`freelance-projects` و`freelance-catalogs` و`freelance-disputes`. قسم «العمل الحر» في الشريط الجانبي (يظهر لما الخدمة المختارة في الهيدر هي العمل الحر) وصفحة نظرة عامة مؤقتة. الاختبار: `tests/Feature/FreelanceModuleTest.php`.

- **المرجع:** [requirements-analysis.md](requirements-analysis.md) (تحليل الوثيقة والقرارات) و[freelance_tasks.md](../../freelance_tasks.md) (المهام).
- **حالة الوثيقة الأصلية بالألوان:** `python docs/modules/freelance/make_status.py` ← `DORR_Freelance_Platform_Requirements_v4_405_status.docx` (أخضر منجز، أحمر مخطط، رمادي مدموج/محذوف) ويحدّث جدول التقدم في ملف المهام.
- **الواجهات:** لوحة الـ admin · تطبيق دُرر (جهة العميل) · تطبيق المحترف (`freelanceApp/`) · موقع المحترفين · داشبورد المحترف. **API موحد** للمحترف: `/api/freelance/v1/*` (حارس `freelance_api`).

## بيانات التجربة (Seeders)

- `FreelanceSettingsSeeder` (جوه `DatabaseSeeder`): مفاتيح الفريلانس لكل دولة مفتوحة (بيضيف الناقص بس، وأي مفتاح الأدمن قفله يفضل مقفول).
- `FreelanceDemoSeeder` (تطوير بس، ومابيشتغلش في production): `php artisan db:seed --class="Modules\Freelance\Database\Seeders\FreelanceDemoSeeder"` — ممكن يتشغّل أكتر من مرة من غير تكرار.
  - **العملاء (تطبيق دُرر):** `+966500000900` … `903` (4 عملاء).
  - **المحترفين (تطبيق المحترف):** `+966500000100` … `107`: موثّقين `100`–`103` (سارة المطوّرة، يوسف، لينا، أحمد — أحمد موقوف الاستقبال)، `104` قيد المراجعة، `105` سجّل لسه، `106` الملف جاهز، `107` توثيقه مرفوض. الكود بتاع التجربة `1234`.
  - **24 مشروع** بكل الحالات (10 منشور، 5 قيد المراجعة، 2 مطلوب تعديل، 2 مرفوض بسبب، 3 مسودة، 2 ملغي)، ورصيد 2500 للمحترفين الموثّقين، وحد أدنى للميزانية 500.
  - **الصور:** كل محترف عنده ملف بياخد أفاتار (لون واختصار الاسم)، وكل عمل في المعرض بياخد صورتين، متولّدة بالـ GD وما بتتكررش لو الـ seeder اتشغّل تاني.
