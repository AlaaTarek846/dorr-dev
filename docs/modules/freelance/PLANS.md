# باقات المحترف والفريق — كتالوج المميزات (FS-705)

> الحالة: **FS-705a اتنفّذ 2026-11-12** (الكتالوج + التخزين + الـ resolver + فتح Team Starter). باقي الشغل: **705b** (ربط المميزات الجديدة بأماكنها + شاشة مصفوفة الأدمن) و**705c** (شاشة المقارنة والقفل في تطبيق المحترف أندرويد + iOS).
> مفيش باقات جديدة: نفس الأربعة — `free` و`plus` و`pro` (للمحترف) و`team_starter` (للفريق).

## قرارات المالك (2026-11-12)

| القرار | التنفيذ |
|---|---|
| `team_starter` تتفتح | `status = true` في الـ config وفي migration `2026_12_13_100000`. بتظهر للفريق بس لو بتدّيه حاجة شغّالة أكتر من المجاني (`TeamPlans::raises` → `PlanFeatures::sellable`). |
| المزايا المالية = رسوم أقل | العمولة (`commission_bp`، نوع `fee_bp`: الباقة بتقلّلها بس). مفيش رسوم سحب في الكود أصلاً (`WalletFeeRuleService` فيه قواعد الشحن بس). |
| باقة الفريق = مميزات Pro **على شغل الفريق بس** | `PlanFeatures::forTeam()` بيقرا باقة الفريق بس، و`forFreelancer()` باقة الشخص بس. عضو في فريق على Team Starter ماياخدش حاجة على حسابه الشخصي (اختبار). |
| الأسعار ($9 / $24 / $29) | نقطة بداية، بتتغيّر من الأدمن. FS-004 (تحليل التكلفة) لسه. |
| مساعد الـ AI للمحترف | مش في الكتالوج لحد ما FS-706 يتبني (مفيش ميزة AI للمحترف في الكود — الـ AI كله للعميل بباقة DORR AI). |

## الكتالوج

مكانه `config('freelance.plan_features')`، والأسماء والشرح في `lang/{ar,en}/freelance.php` → `plan_features.<key>.{name,hint}`.

كل ميزة:
- **type**: `switch` (0/1) · `level` (0..max) · `limit` (رقم، `null` = من غير حد) · `fee_bp` (نقاط أساس، الباقة بتقلّلها بس).
- **audience**: `freelancer` / `team` / الاتنين.
- **column**: المميزات القديمة (قبل FS-705) فاضلة في أعمدتها في `freelance_plans`؛ الجديدة صفوف في `freelance_plan_features` (`plan_id`, `key`, `value`).
- **base**: اللي المحترف/الفريق بياخده من غير باقة: مفتاح سياسة الدولة، أو مسار config، أو رقم ثابت.
- **live**: الجماهير اللي الكود بيطبّق عليها الميزة النهارده. اللي مش live بيتعرض للأدمن «جاية» ومابيتباعش ولا بيتحسب سبب لشراء باقة.

### القاعدة الواحدة (`PlanFeatures`)
الباقة **بتحسّن بس، عمرها ما تقلّل** عن اللي البلد بتدّيه من غير باقة:
- `switch`: بتاع الباقة لو محدّد، وإلا مقفول.
- `level`: بتاع الباقة (لحد `max`)، وإلا 0.
- `limit`: لو الأساس من غير حد → من غير حد؛ لو الباقة رفعت الحد → من غير حد؛ وإلا الأكبر من الاتنين.
- `fee_bp`: الأقل من الاتنين.
- Free مابتدّيش حاجة. عمود فاضي = يتبع الأساس، إلا اللي مكتوب فيه `null => no_cap` (أعضاء/مشاريع الفريق).

### المصفوفة وقيمها المبدئية

| المفتاح | النوع | الجمهور | الأساس | Plus | Pro | Team Starter | live |
|---|---|---|---|---|---|---|---|
| `offers_monthly` | limit | الاتنين | `offers.free_monthly_quota` / `teams.offers_monthly` | 40 | بلا حد | 60 | محترف + فريق |
| `commission_bp` | fee_bp | الاتنين | `commission.value` | 800 | 600 | 600 | محترف |
| `special_offers_daily` | limit | محترف | `special_offers.daily_limit` | 20 | 50 | — | 705b |
| `offer_templates_max` | limit | محترف | 10 (`OfferChecks::MAX_TEMPLATES`) | 20 | 50 | — | 705b |
| `loyalty_tiers_max` | limit | محترف | `loyalty.max_tiers` | 6 | 8 | — | 705b |
| `badge` | switch | الاتنين | — | ✓ | ✓ | ✓ | محترف |
| `boost` | level 0..3 | الاتنين | — | 1 | 2 | 2 | محترف |
| `portfolio_max` | limit | محترف | `profile.max_portfolio` | 50 | 100 | — | 705b |
| `team_portfolio_max` | limit | فريق | `profile.max_portfolio` | — | — | 60 | 705b |
| `services_max` | limit | محترف | `services.max_active` | 15 | 30 | — | محترف |
| `consultations_max` | limit | محترف | `consultations.max_active` | 10 | 20 | — | 705b |
| `capacity_max` | limit | محترف | `capacity.max_choice` | 30 | 50 | — | 705b |
| `analytics` | level 0..2 | الاتنين | — | 1 | 2 | 2 | محترف |
| `crm` | level 0..2 | الاتنين | — | 1 | 2 | 2 | محترف |
| `crm_reminders_max` | limit | محترف | `crm.reminders_max` | 30 | 100 | — | 705b |
| `workroom_storage_mb` | limit | الاتنين | `workroom.storage_mb` | 5120 | 20480 | 20480 | 705b |
| `team_members_max` | limit | فريق | `teams.members_max` | — | — | 10 | فريق |
| `team_projects_max` | limit | فريق | `teams.projects_max` | — | — | 5 | فريق |
| `team_staff_max` | limit | فريق | 20 (`TeamOffers::STAFF_MAX`) | — | — | 30 | 705b |
| `priority_support` | switch | الاتنين | — | — | ✓ | ✓ | 705b |

## الكود

| الملف | الدور |
|---|---|
| `Modules/Freelance/app/Services/Plans/PlanFeatures.php` | الـ resolver: `forFreelancer` / `forTeam` / `value` / `base` / `raw` / `matrix` / `sellable` / `lowestPlanBeyond` / `set` / `seedDefaults` / `catalog` |
| `Modules/Freelance/app/Models/FreelancePlanFeature.php` + `FreelancePlan::features()` | صفوف المميزات الجديدة |
| `database/migrations/2026_12_13_100000_create_freelance_plan_features.php` | الجدول + فتح Team Starter + مميزات Pro على شغل الفريق + القيم المبدئية (من غير ما يكتب فوق تعديل أدمن) |
| `FreelancePlanDefaults::seed()` | بيضيف الناقص من المميزات في كل deploy (`features` في النتيجة) |
| `TeamPlans::limitsFor` / `raises` | بقوا يقروا من الكتالوج (نفس نتيجة FS-211) |
| `FreelancePerks::planForCrm` / `planForAnalytics` | بقوا يقروا من الكتالوج **وباقات المحترف بس** — قبل كده كانوا ممكن يقترحوا Team Starter على محترف لوحده بعد فتحها |

الاختبارات: `tests/Feature/FreelancePlanFeaturesTest.php`.

## الباقي (705b / 705c)

- **705b**: كل ميزة `live: []` تتربط في مكانها (الجرد تحت) وتتعلّم live؛ شاشة مصفوفة الأدمن (معيار شاشات الأدمن)؛ الأدمن يعدّل عمولة/شارة/boost/أرقام/CRM لباقة الفريق (`FreelancePlanController::update` لسه بيشيلهم من باقة الفريق).
- **قرار مفتوح (NEEDS-DECISION) لـ 705b**: عرض الفريق النهارده بيتحسب بعمولة **باقة الـ Owner الشخصية** (`FreelancerOffers.php:157-173`). مع قرار «شغل الفريق ياخد باقة الفريق بس»، المفروض عمولة الفريق تيجي من باقة الفريق. ده هيغلّي العمولة على Owner عنده Pro وفريقه على Free — محتاج تأكيد قبل التغيير.
- **705c**: `GET plans` و`teams/{team}/subscription` يرجّعوا الكتالوج + القيم؛ شاشة مقارنة؛ القفل في كل شاشة يقول الميزة وأقل باقة بتفتحها؛ iOS twin.

## الجرد — أماكن التطبيق للمميزات الجديدة (لـ 705b)

| الميزة | مكان الحد النهارده |
|---|---|
| `consultations_max` | `ConsultationListings.php:318` (`consultations.max_active`) |
| `special_offers_daily` | `SpecialOffers.php:76` |
| `loyalty_tiers_max` | `Loyalty.php:75` |
| `portfolio_max` / `team_portfolio_max` | `FreelancerPortfolio.php:51,118` |
| `offer_templates_max` | `OfferChecks.php:22,81` |
| `capacity_max` | `FreelanceCapacity.php:32` |
| `crm_reminders_max` | `FreelancerCrm.php:147` |
| `workroom_storage_mb` | `WorkroomFiles.php:274` |
| `team_staff_max` | `TeamOffers.php:36,117` |
| `priority_support` | مفيش أولوية في الدعم النهارده — محتاج تصميم في 705b |
| شارة/boost/أرقام/CRM للفريق | مفيش شارة ولا boost للفريق النهارده (`ServiceCatalog`، `ProjectInvitations`، `ClientOfferViews`) |

قاعدة 705b: ما نقفلش ميزة مجانية موجودة النهارده (زي الـ Workload وصلاحيات الأدوار) — الباقة بتزوّد فوق المجاني بس.
