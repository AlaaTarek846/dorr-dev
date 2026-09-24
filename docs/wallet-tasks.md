# Wallet — خطة التنفيذ بالمهام (DRAFT)

> **الحالة: غير معتمدة.** ترتيب مقترح للتنفيذ بناءً على [wallet-plan.md](wallet-plan.md) و[wallet-structure.md](wallet-structure.md).
> **ممنوع البدء في أي مهمة من دول** قبل ما "المرحلة صفر" تتقفل (القرارات المعلّقة) — حسب [AGENTS.md](../AGENTS.md).

آخر تحديث: 2026-09-23 — بعد مراجعة `dorr-wallet-schema.pdf` وأخذ اللي يفيدنا منه (minor units، composite FKs، idempotency+hash، webhook_inbox، wallet_pins، Hold/Capture مُوحّد مع السحب) من غير تعقيد زيادة (رُفض نموذج الـ Double-Entry الكامل).

**إزاي تقرأ الملف ده:** كل مرحلة **بتعتمد على اللي قبلها** وبترجّع Deliverable واضح وقابل للاختبار قبل ما ننتقل للي بعدها. جوه كل مرحلة، المهام مرتبة بالترتيب اللي هتتنفذ بيه فعلياً.

---

## المرحلة 0 — قفل القرارات (قبل أي سطر كود)

مفيش migration ولا كود قبل ما الأسئلة دي تتجاوب (القائمة الكاملة في [wallet-plan.md § 16](wallet-plan.md#16-النقاش-إيه-الصح-وإيه-اللي-محتاج-تفكير) و[wallet-structure.md § 11](wallet-structure.md#11-قرارات-محسومة-بعد-مراجعة-dorr-wallet-schemapdf--افتراضات-لسه-مفتوحة)).

**اتقفلت بالفعل (مش محتاجة رد تاني):** مكان الكود (`Modules/Wallet`)، `bigint` minor units بدل `decimal`، `idempotency_key`+`request_hash`، قفل بترتيب `id`، `webhook_inbox`، `wallet_pins`، ورفض نموذج الـ Double-Entry الكامل — التفاصيل في `wallet-structure.md § 11`.

**لسه محتاجة رد:**
- [ ] محفظة النظام (`platform`) بالتصميم المقترح (`owner_id=0`) ولا جدول مستقل؟
- [ ] بيانات اعتماد MyFatoorah/URPay/ARB — حسابات LeeTaxi/Jawad ولا جديدة؟
- [ ] تفعيل التحويل بين المستخدمين من أول نسخة ولا لأ (`wallet_settings.transfers_enabled`)؟
- [ ] الشحن `withdrawable` فوراً ولا فترة انتظار (Chargeback)؟
- [ ] تاكسونومي `financial_categories` الكاملة (غير الأربع فئات الأساسية)؟
- [ ] الصلاحيات: نستخدم `spatie/laravel-permission` الموجود بالفعل في `composer.json` من الأول، ولا نأجّل الحماية التفصيلية؟
- [ ] PIN على "تعديل/إضافة وسيلة سحب"؟ وإنشاء أول PIN إجباري وقت التسجيل ولا اختياري؟ (تفاصيل `wallet-structure.md § 1.2`)

**Deliverable:** تحديث `wallet-plan.md` بالإجابات، وتحويل كل "افتراض" متبقي في `wallet-structure.md § 11` لقرار نهائي.

---

## المرحلة 1 — الأساسات المشتركة (Foundation)

**الهدف:** سقالة الموديول + الإعدادات الأساسية، بدون أي منطق مالي لسه.

1. ✅ إنشاء الموديول `Modules/Wallet` (nwidart) + تسجيله في `modules_statuses.json`.
2. ✅ `Modules/Wallet/config/config.php`: alias map (`user`/`provider`/`admin`) مقروء عن طريق `Modules\Wallet\Support\OwnerType` — **مش** `Relation::morphMap()`/`enforceMorphMap()` (بتكسر علاقات polymorphic تانية غير متعلقة بالمحفظة على نفس الموديلات، اتكشف فعلياً وقت التنفيذ بكسر اختبار الـ OTP الموجود). `platform` بدون موديول حقيقي (بند 1.1).
3. ✅ Migration: `wallet_settings` (جدول 3) + Model + `WalletSettingRepository` (على نمط `CountryRepository`، من غير الترجمة).
4. ✅ `WalletSettingObserver` على `Country::created` (بيتسجل في `WalletServiceProvider::boot()`) + `WalletDatabaseSeeder` (backfill idempotent لكل الدول الموجودة — ضروري لأن `DatabaseSeeder` بيستخدم `WithoutModelEvents` فالـ Observer مبيشتغلش وقت الـ seed العادي) + إضافته لـ `database/seeders/DatabaseSeeder.php`. اتشغّل فعلياً على `dorr.test`: 247 دولة، 247 `wallet_settings`.
5. ⏳ شاشة أدمن بسيطة (CRUD) لتعديل `wallet_settings` لكل دولة، على نمط `PlatformSettingController` — **لسه، مؤجّلة للمرحلة 7 مع باقي شاشات الأدمن**.
6. ✅ Migration: `wallet_pins` (جدول 1.2) + Model `WalletPin` + `PinService` (`has()`/`set()`/`verify()`، Argon2id + pepper من `config('wallet.pin_pepper')` — `WALLET_PIN_PEPPER` في `.env`)، عدّاد `failed_attempts` + قفل 15 دقيقة بعد 5 محاولات فاشلة (`PinNotSetException`/`PinMismatchException`/`PinLockedException`).
7. ⏳ Middleware `RequiresWalletPin` — لسه، هيتفعّل مع أول Route محتاجه فعلياً (المراحل 6/8/10/11).
8. ✅ اختبار: إنشاء دولة جديدة → `wallet_settings` بتتعمل تلقائي (`tests/Feature/WalletPinServiceTest.php::setUp`).
9. ✅ اختبار: `PinService` — 6 اختبارات (`tests/Feature/WalletPinServiceTest.php`): مفيش PIN، تحقق صح، تحقق غلط بيعدّ المحاولة، قفل بعد 5 محاولات، الـ alias بيتخزن صح. **كل الـ 25 اختبار في المشروع (بما فيهم دول) لسه ناجحين.**

**Deliverable (اتحقق فعلياً):** موديول Wallet موجود ومسجّل، كل دولة ليها إعدادات محفظة تلقائي، PIN شغال بمنطق القفل، من غير أي محفظة أو حركة لسه. **باقي من المرحلة دي:** شاشة الأدمن (بند 5) والـ Middleware (بند 7).

---

## المرحلة 2 — نواة المحفظة (`WalletService`) 🔴 أهم مرحلة

**الهدف:** الجداول والخدمة اللي كل حاجة تانية هتُبنى عليها.

1. ✅ Migration: `wallets` (جدول 1) + Model `Wallet` + trait `HasWallets` (`Modules\Wallet\Concerns`) اتضاف لـ `User` و`Provider`. `owner()` accessor يدوي (`OwnerType`)، `wallets()`/`walletFor()` بترجّع `Builder` مش Eloquent relation رسمية — لنفس سبب `OwnerType` (فصل 0).
2. ✅ Migration: `wallet_transactions` (جدول 2) + Model `WalletTransaction` — immutable فعلاً: `booted()` بيرمي `LogicException` على `updating`/`deleting`، و`UPDATED_AT = null` على مستوى الموديل كمان.
3. ✅ Migration: `wallet_holds` (جدول 2.1) + Model `WalletHold` — guard بيرفض أي تعديل بعد ما الحالة توصل نهائية (`captured`/`released`/`expired`).
4. ✅ `WalletService` (`Modules\Wallet\Services`) — الباب الوحيد لتغيير أي رصيد، كل الدوال جاهزة ومُختبرة: `firstOrCreateWallet()`, `credit()`, `debit()`, `transfer()`, `reverse()`, `manualAdjustment()`, `hold()`, `capture()`, `release()`, `releaseExpiredHolds()`. القفل بترتيب `id` تصاعدي، `idempotency_key`+`request_hash` (409 عند التعارض)، `spend_only` مينفعش يبقى سالب، `withdrawable` مسموح (فصل 11 في هذا الملف).
5. ✅ أمر `wallet:reconcile` (`Modules\Wallet\Console\ReconcileWallets`) — بيعيد حساب الأرصدة من `wallet_transactions` ويقارنها بـ `wallets` (log عند أي فرق)، وبيحرر أي `wallet_holds` بحالة `active` عدّى `expires_at`. مجدول Hourly في `WalletServiceProvider::configureSchedules()`.
6. ⏳ Resource/API (`WalletTransactionResource`) — **لسه، مؤجّل للمرحلة 7** مع باقي الـ Resources (محتاج ترجمة `notes.key` لكل `type`، أنسب مكانه مع باقي شاشات العرض).
7. ✅ **اختبارات (13 اختبار، `tests/Feature/WalletServiceTest.php`، كلهم ناجحين):**
   - ✅ المثال المحسوب بالكامل من `wallet-plan.md § 10` حرفياً (شحن 1000 → تحويل 500 → رجوع 500 → القابل للسحب = 500، الإجمالي = 1000).
   - ✅ `spend_only` مايتعداش الصفر (`InsufficientBalanceException`)، و`withdrawable` مسموح يبقى سالب.
   - ✅ `update()`/`delete()` مباشر على `WalletTransaction` → `LogicException`.
   - ✅ idempotency: نفس المفتاح مرتين بنفس البيانات → نفس الصف (مفيش تكرار). نفس المفتاح ببيانات مختلفة → `IdempotencyConflictException`.
   - ✅ `hold()` بيقلل المتاح من غير أي صف `wallet_transactions`.
   - ✅ `capture()` جزئي → صف واحد بالمبلغ الفعلي، الفرق يترجع متاح فوراً، الحجز يقفل `captured`.
   - ✅ `release()` → المتاح يرجع زي ما كان، صفر صفوف جديدة.
   - ✅ حجز `active` عدّى `expires_at` → `wallet:reconcile` بيحرره تلقائي.
   - ✅ `wallet:reconcile` بيرجع exit code 0 (مطابق) بعد سلسلة عمليات (شحن + تحويل + حجز + تحصيل + خصم).
   - ✅ `reverse()` بيعمل صف عكسي، والمحاولة التانية على نفس الصف بترفض.
   - ⚠️ **سباق متزامن حقيقي (طلبين في نفس اللحظة، وسباق تحويل A↔B) — لسه محتاج اختبار على MySQL حقيقي بـ processes منفصلة**، مش SQLite (بيئة الاختبار الافتراضية للمشروع، `phpunit.xml`). الـ `lockForUpdate` + ترتيب الـ `id` جاهزين في الكود، بس التأكد الفعلي من عدم الـ deadlock/double-spend تحت حمل حقيقي محتاج تشغيل يدوي أو سكريبت منفصل قبل الإطلاق.

**Deliverable (اتحقق فعلياً على `dorr.test`، migrations على MySQL 8.0.30 + CHECK constraints شغالة):** تقدر تنشئ محفظة، تزوّد/تخصم رصيدها بفلاج صح، تحوّل بين محفظتين، تحجز/تحصّل/تحرر، وتعكس عملية — كله من غير أي واجهة API لسه. **كل الـ 38 اختبار في المشروع ناجحين (25 قديم + 13 جديد).**

---

## المرحلة 3 — تحديد الدولة (`CountryResolver`)

1. ✅ **اكتشاف أثناء التنفيذ:** `app/Support/helpers.php::getCountryCodeByIp()` كان أصلاً اتصلّح (مش نفس نسخة LeeTaxi الأصلية اللي راجعناها في `wallet-plan.md § 8`) — فيه cache يوم لكل IP، `timeout(3)`، try/catch، وfallback chain بـ `is_default`/`status` مش قيمة ثابتة. **قرار: إعادة استخدامه** كخطوة IP جوه `CountryResolver` بدل ما نبني نظام `config/geo.php` قابل للتبديل من الصفر — كان هيبقى ازدواجية.
2. ✅ `CountryResolver` (`app/Services/General/CountryResolver.php`، مش جوه Wallet — بيُستخدم في سياقات تانية غير المحفظة): الترتيب زي ما اتفقنا بالظبط — اختيار صريح (`X-Country` header أو `?country=`) → دولة البروفايل (`user_api`/`provider_api` بس، **مش أدمن عمداً**) → `getCountryCodeByIp()` → `is_default`.
3. ✅ Middleware `ResolveCountryContext` (alias `country` في `bootstrap/app.php`) — بيحط الدولة في `$request->attributes` وفي الـ container، وبيتاح بدالة `currentCountry()` في `helpers.php` لأي كود من غير الحاجة للـ Request.
4. ✅ اختبار: `Http::fake()` بيحاكي انقطاع geoplugin.net بالكامل (`ConnectionException`) → `CountryResolver` يكمل عادي ويرجّع الدولة الافتراضية من غير أي 500.

**Deliverable (اتحقق فعلياً):** أي Request عنده دولة سياق واضحة، حتى لو من غير مستخدم مسجّل دخول. **6 اختبارات جديدة ناجحة** (`tests/Feature/CountryResolverTest.php`) — أولوية الاختيار الصريح، أولوية البروفايل، fallback الـ IP، fallback الافتراضي عند الانقطاع، تجاهل كود دولة غير معروف بأمان، والـ middleware end-to-end. **كل الـ 44 اختبار في المشروع ناجحين.**

---

## المرحلة 4 — الحسابات المالية للنظام (`FinancialLedgerService`)

**ليه قبل الشحن والرسوم؟** عشان لما نبني الشحن في المرحلة 6، كل حركة رسوم/هدية تتسجل هنا من أول يوم، مش نرجع نضيفها لاحقاً.

1. ✅ Migrations: `financial_categories`, `financial_category_translations`, `financial_entries` (جدول 5). **درس أثناء التنفيذ:** اسم فهرس `unique(financial_category_id, locale)` الافتراضي عدّى حد الـ 64 حرف بتاع MySQL identifiers — لازم اسم صريح مختصر لأي unique/index مركّب على أسماء أعمدة طويلة.
2. ✅ Seeder (`FinancialCategorySeeder`) — **تعديل عن الخطة:** بدل فئة واحدة غامضة `manual_adjustment`، اتقسمت لـ `manual_adjustment_income` و`manual_adjustment_expense` لأن قاعدة "النوع لازم يطابق الفئة" (بند 3) بتمنع فئة واحدة تقبل الاتجاهين. الفئات الخمسة: `topup_fee` (income)، `promo_bonus_cost` (expense)، `withdrawal_processing` (expense، نوع مبدئي لحد ما يتحدد قرار رسوم السحب)، `manual_adjustment_income`، `manual_adjustment_expense` — كلهم `is_system=true` بترجمة ar/en، ومسجّلين في `WalletDatabaseSeeder`.
3. ✅ `FinancialLedgerService::record()` (`Modules\Wallet\Services`) — بيرمي `FinancialCategoryNotFoundException` واضح لو الـ slug مش موجود (مفيش fallback صامت زي `category_id => 1` في Jawad)، و`FinancialEntryTypeMismatchException` لو النوع مايطابقش فئته.
4. ✅ Guard في موديل `FinancialCategory` (`booted()`) يمنع حذف أو تعديل `slug` لو `is_system=true` — بيرمي `LogicException`.
5. ⏳ شاشة أدمن — **لسه، مؤجّلة للمرحلة 7** مع باقي شاشات العرض (نفس قرار `WalletTransactionResource` في المرحلة 2).

**Deliverable (اتحقق فعلياً):** دفتر إيرادات/مصروفات شغال ومربوط، جاهز يستقبل أول صف من المرحلة اللي بعدها — بما فيه ربط اختياري بـ `wallet_transaction_id`. **6 اختبارات جديدة ناجحة** (`tests/Feature/FinancialLedgerServiceTest.php`)، **كل الـ 50 اختبار في المشروع ناجحين**.

---

## المرحلة 5 — طرق الدفع + بوابة واحدة

1. ✅ Migrations: `payment_methods`, `payment_method_translations`, `payment_method_country` (جدول 8) — بأسماء unique صريحة مختصرة (درس المرحلة 4).
2. ✅ `PaymentGateway` interface (`Modules\Wallet\Contracts`) + `PaymentGatewayRegistry` (بيختار الـ driver من `payment_methods.gateway`). **قرار تصميم:** الـ drivers **stateless وما تعرفش `payment_transactions` أصلاً** (الجدول ده مرحلة 6) — بتتعامل بـ value objects (`GatewayChargeRequest`/`GatewayChargeResult`/`GatewayCallbackResult`)، والـ `extra`/`$context` هو الحالة اللي المرحلة 6 هتحفظها وترجّعها (زي سياق URPay بين `initiate` وإدخال الـ OTP).
3. ✅ **التلات بوابات اتنقلوا مش واحدة بس** (طلبك: متنساش انتجريشن جواد وليي تاكسي) — الكود الفعلي اتنقل بأمانة من المشروعين، مع استبدال `env()`/الـ trait بـ credentials لكل طريقة دفع:
   - **`MyFatoorahGateway`** ← LeeTaxi (`MyFatoorahUtil` + `MyFatoorahOnlinePaymentService`): `SendPayment` للبدء، و`GetPaymentStatus` server-to-server للتأكيد. الـ redirect وحده مبيتصدّقش أبداً (فيه اختبار: `?status=success` مزوّر بدون `paymentId` → مرفوض ومفيش أي طلب اتبعت). قيمة `'Succss'` محفوظة حرفياً من المصدر (قيمة API فعلية مش typo عندنا).
   - **`ArbGateway`** ← Jawad (`ARBPaymentService`): غلاف AES-256-CBC + PKCS5 + hex↔bytes منقول حرفياً (بروتوكول البنك نفسه)، `initiate`/`handleCallback` (فك `trandata` + `CAPTURED`)/`verify` (inquiry بـ `action=8` على `tranportal.htm`). اختبار round-trip بيثبت إن التشفير المنقول شغال.
   - **`UrPayGateway`** ← Jawad (`URPaymentService`): مش redirect، تدفق OTP (`generatetoken` ← `initiate` ← `execute`)، `redirectUrl = null`.
   - **`verify()`/`refund()` غير المدعومين بيرموا `UnsupportedGatewayOperationException` بصراحة بدل ما نخترع endpoint:** URPay مفيهوش endpoint استعلام مستقل في كود Jawad، ومفيش أي بوابة من التلاتة فيها refund-to-gateway في المصدرين. **ده بيكسر جزئياً قاعدة "verify إجباري في كل driver" (`wallet-structure.md § 9.3`) لـ URPay بس** — تأكيد الدفع عنده هو خطوة `execute` بالـ OTP نفسها. القرار عندك: نستنى توثيق رسمي من URPay لـ endpoint استعلام، ولا نقبل إن تسوية الأدمن لعمليات URPay المعلّقة مش ممكنة.
4. ✅ CRUD أدمن (`Modules\Wallet\Http\Controllers\Admin\PaymentMethodController` على نمط `CatalogController`/`CountryController` بالظبط): store/update/delete/restore/force/status/dropdown + `PUT payment-methods/{id}/countries` (بيستبدل مجموعة الدول كلها، مع min/max/status لكل دولة). `credentials` بـ `encrypted:array`، و`PaymentMethodResource` **مبيقراش `credentials` خالص** (مش بيخفيه — أضمن). صلاحيات `payment-methods.*` اتضافت في `AdminPermissionSeeder` (لازم يتعاد تشغيله عشان الـ super-admin ياخدها). **اكتشاف:** `SyncsTranslations`/`FormatsTranslations` المشتركين بيحفظوا/يعرضوا `name` بس، فـ `description` كان هيتسيب بصمت — اتعمل override في `PaymentMethodRepository` و`PaymentMethodResource` (فيه اختبار إن الـ description بيتحفظ).
5. ✅ Endpoint عام: `GET mobile/v1/wallet/payment-methods` (تطبيق الموبايل، `auth:user_api` + `ensure-phone-verified` + `throttle`) و`GET provider/v1/wallet/payment-methods` — الاتنين ورا middleware `country` (المرحلة 3) فبيرجّعوا طرق الدفع للدولة المحسوبة. الـ routes اتضافت في ملفات جديدة (`admin.php`/`mobile.php`/`provider.php`) بتتحمّل من آخر `routes/api.php` **من غير ما أمس الـ stub الافتراضي**.
6. ✅ `PaymentMethodSeeder` (مسجّل في `WalletDatabaseSeeder`): التلات بوابات موجودين كصفوف، بس **بيتفعّل بس اللي معاه credentials في `.env`** (مفتاح `config('wallet.gateway_seed_credentials')`، متوثّق في `.env.example`) — غير كده `status=false`، لأن تفعيل بوابة مش هتعرف تعمل authenticate هيفشل بس وقت الشحن. كلهم متربطين بالسعودية (`SA`) مش بأي دولة `is_default` (ARB وURPay سعوديين بطبيعتهم).
7. ✅ **30 اختبار جديد ناجح:** `tests/Feature/PaymentGatewayTest.php` (17 — HTTP مزيّف بالكامل، مفيش بوابة حقيقية اتكلمت) و`tests/Feature/PaymentMethodTest.php` (13) — الإتاحة (دولة تانية ما تظهرش، `is_global` تظهر للكل، غير فعّال ما يظهرش حتى لو global، ربط معطّل يخفيها لدولته بس)، التشفير at-rest، عدم ظهور `credentials` في أي response، صلاحيات الأدمن، إن الطريقة online من غير credentials بتترفض 422.

**Deliverable (اتحقق):** أدمن يقدر يضيف طريقة دفع ويحدد لأي دول، ومستخدم أي دولة يشوف الطرق الصح بس. **كل الـ 80 اختبار في المشروع ناجحين.** ⚠️ **لسه ما اتجربش أي بوابة حقيقية** — مفيش credentials حقيقية، فكله متأكد بـ HTTP مزيّف بأشكال الـ request/response من كود المصدرين.

---

## المرحلة 6 — الشحن الأونلاين + `quote` + الرسوم/الهدية ✅ (Backend + Admin API)

> **الحالة: اتنفّذت وكل الـ 121 اختبار في المشروع ناجحين** (80 قديمة + 41 جديدة في `tests/Feature/PaymentTopupTest.php`). شاشات Vue للأدمن والموبايل مؤجلة (6.1 / 7). الملخص أدناه، والبنود الأصلية تحته للمرجع.
>
> **اللي اتبنى:** `FeeService` (اختيار القاعدة الأخص + حساب النسبة بـ basis points صحيحة، half-up) · `PaymentTopupService` (quote/initiate/confirmOtp، idempotency مقيّد بالمالك) · `PaymentCompletionService` (الاكتمال الذرّي الوحيد) · `PaymentCallbackService` · `PaymentReconciliationService` · `PaymentRefundService` · `PaymentLogger` · `RequiresWalletPin` (header `X-Wallet-Pin`) + endpoints الـ PIN · `payment:expire-stale` (كل 5 دقايق) · أدمن: `wallet-fee-rules` (CRUD) و`online-transactions` (قائمة/ملخص بالعملة/تفاصيل بسجل البوابة/reconcile/refund) · صلاحيات `wallet-fee-rules.*` و`online-transactions.{view,reconcile,refund}` في `AdminPermissionSeeder`.
>
> **انحرافات عن التصميم الأصلي (اتكتشفت أثناء التنفيذ):**
> 1. **مفيش بوابة من التلاتة بتبعت webhook منفصل** — الـ redirect نفسه هو الإشعار. فـ `webhook_inbox` بيعمل dedup/audit للـ redirects الداخلة (`event_id` = payment id بتاع البوابة)، والأمان جاي من إن كل driver بيعيد التحقق من البوابة server-to-server قبل ما يصدّق أي حاجة، مش من التوقيع. الـ redirect المكرر اللي اتعالج بالفعل no-op.
> 2. أعمدة زيادة على `wallet-structure.md`: `payment_transactions.redirect_url` (عشان الـ replay يرجّع نفس اللينك)، `gateway_context` (`encrypted:array` — حالة الـ driver زي توكن URPay، `hidden`)، و`payment_gateway_logs.payment_transaction_id` بقى nullable + عمود `external_reference` (عشان callback لـ reference مجهول يتسجل بدل ما يتحذف).
> 3. **الـ callback عام** (`ANY api/wallet/payments/{uuid}/callback`) ويرد صفحة HTML صغيرة (المتصفح مش API client). المرجع = `uuid` غير قابل للتخمين، ومفيش حاجة في الطلب بتتصدّق.
> 4. **Reconcile مبيفشّلش أبداً** عملية مش متأكدة من البوابة (البنك ممكن يكون لسه بيعالج) — بس بيسجّل. الـ callback العميل هو اللي بيحوّل لـ `failed`. عملية `expired` لسه قابلة للتسوية.
> 5. **اختلاف مبلغ** (البوابة بتقول رقم غير `requested_amount_minor`) → مبيتضافش أي رقم، والعملية بتفضل `pending` بـ `failure_reason=amount_mismatch` للمراجعة.
> 6. **Refund** = عكس داخل المحفظة فقط (الشحن + الرسوم + الهدية في عملية واحدة، وحذف soft لقيود الدخل/التكلفة، وإرجاع ميزانية القاعدة). مسموح بس لو الرصيد المتاح لسه يغطي الصافي + الهدية (`refund_not_whole` غير كده). **رد الفلوس للعميل على الكارت بيتم يدوياً من بوابة الدفع** — مفيش أي مصدر من التلاتة فيه refund-to-gateway.
> 7. الهدية (`percent<0`) **لازم** لها `max_amount_minor` (cap) في الـ validation، وأي نسبة ≠ 0 لازم `percent_confirmation`. ميزانية العرض cap ناعم عند الـ quote؛ الاكتمال بيحترم اللقطة.
> 8. **مفتوح:** URPay `verify()` غير مدعوم → Reconcile لعملياته بيرجّع 422 `reconcile_unsupported` (قرارك: نستنى توثيق ولا نقبل).
>
> **لسه ناقص من المرحلة:** أي بوابة حقيقية ما اتجربتش (كله HTTP مزيّف)، واختبار concurrency حقيقي على MySQL بعمليات منفصلة.

1. Migrations: `payment_transactions` + **`webhook_inbox`** + **`payment_gateway_logs`** (جداول 9، 9.1، 9.2 في `wallet-structure.md`).
2. `wallet_fee_rules` (+ الترجمة): Migration جدول 4، CRUD أدمن، مع validation صارم على `percent` (سقف أقصى مطلق + تأكيد مزدوج قبل الحفظ).
3. Endpoint `quote`: يدخل مبلغ + طريقة دفع → يرجّع القاعدة المطبّقة (لو فيه) + الصافي/الهدية المتوقعة، ويخزّن لقطة في `payment_transactions` (`fee_rule_id`, `fee_percent`, `quoted_net_amount_minor`, `quoted_bonus_amount_minor`) مع `idempotency_key`+`request_hash`.
4. **`RequiresWalletPin` middleware على Route الشحن** (المرحلة 1) — طلب الشحن يترفض من غير PIN صحيح.
5. بدء الدفع (`initiate`) عبر `PaymentGatewayRegistry` بناءً على اللقطة — كل نداء (طلب/رد) بيتسجل صف في `payment_gateway_logs` (`event=initiate`).
6. `Gateway::verify()` **إجباري في كل driver من أول نسخة** (مش تحسين لاحق، بند 9.3): استعلام server-to-server عن الحالة الحقيقية عند البوابة — نفس نمط `GetPaymentStatus` (MyFatoorah) و`decryptAndGetTransaction` (ARB). **ممنوع أي driver يثق في querystring/body الـ redirect من غير التحقق ده.**
7. **مسار استقبال الـ webhook:** أي POST جاي من البوابة → تحقق توقيع → INSERT في `webhook_inbox` بـ `(provider_code, event_id)`. لو الصف موجود بالفعل (تكرار) → رد 200 فوراً من غير أي معالجة تانية. لو جديد → استدعاء `PaymentCompletionService`.
8. `PaymentCompletionService::completeSuccessfulPayment()` (مركزية، idempotent):
   - `DB::transaction` + `lockForUpdate` على صف `payment_transactions` **أولاً**، تحقق إن الحالة لسه `pending` (منع معالجة مزدوجة من webhook + تسوية يدوية متزامنين).
   - المبلغ اللي بيتضاف للمحفظة **دايماً من `payment_transactions.requested_amount_minor` المحلي**، مش من أي رقم راجع من البوابة (بند 9.3).
   - يستدعي `WalletService::credit()` بمبلغ الشحن الأساسي (`withdrawable`) **في نفس الـ transaction**.
   - لو فيه قاعدة رسوم/هدية مطبّقة: صف تاني منفصل بنفس `operation_id` + `FinancialLedgerService::record()` مقابل.
   - يحدّث `payment_transactions.status = paid` و`processed_at` **جوه نفس الـ transaction** (ذرّية، بند 9.3 — مفيش حفظين منفصلين).
   - **null-guard إجباري:** لو الـ reference/id جاي من البوابة مش معروف محلياً، يتسجل في `payment_gateway_logs` ويترفض بأمان — **من غير Exception** (تجنب باگ `find()->update()` في LeeTaxi).
9. أمر مجدول `payment:expire-stale` — يحوّل `pending` اللي عدّى `expires_at` لـ `expired` (لسه قابلة للتسوية اليدوية بعد كده).
10. شاشة أدمن **"Online Transactions"** (زي `FinanceController::onlineTransactionsApi` في Jawad، بس من أول نسخة مش إضافة لاحقة):
    - قائمة + فلاتر (حالة/تاريخ/بحث بمرجع البوابة) + ملخص (إجمالي ناجح/معلّق).
    - تفاصيل عملية: آخر طلب/رد + سجل `payment_gateway_logs` كامل + سجل `webhook_inbox` المرتبط.
    - زرار **"تسوية" (Reconcile)** على أي عملية `pending`/`expired`: بينادي `Gateway::verify()` (بند 6) وبعدين `PaymentCompletionService` (idempotent)، ويزوّد `reconciliation_attempts`، ويسجّل الأدمن في `payment_gateway_logs` (`event=manual_reconcile`, `triggered_by`).
11. `translateError(code, locale)` على كل Gateway driver (مفاتيح ترجمة، مش كلاس أخطاء منفصل زي `ARBErrorMessages`).
12. Endpoint استرجاع شحن (Refund) — بالقيد من `wallet-plan.md § 12`: مسموح بس لو الشحن الأصلي (والهدية المرتبطة) لسه كامل، وبيلغي الحركتين مع بعض بصف عكسي.
13. اختبارات:
    - السيناريو الكامل بالظبط من `wallet-plan.md § 12` (شحن 100 بـ −10% → 110 على المحفظة → استرجاع قبل أي تصرف → الهدية بتتشال).
    - عملية `pending` عالقة (الـ callback اتقطع) → زرار "تسوية" بينجح ويضيف الرصيد مرة واحدة بس.
    - webhook مكرر (نفس `event_id` توصل مرتين) → صف واحد في `webhook_inbox`، وصف محفظة واحد بس.
    - webhook بتوقيع باطل → يترفض ويتسجل، ومبيوصلش لـ `PaymentCompletionService`.
    - reference غير معروف في الـ callback → رد آمن من غير Exception.
    - طلب شحن من غير PIN صحيح → يترفض قبل ما يوصل للبوابة أصلاً.

**Deliverable:** مستخدم/مزوّد يقدر يشحن محفظته فعلياً، ويشوف عرض السعر قبل الدفع، والرسوم/الهدايا بتتسجل صح في المحفظة والحسابات المالية، **ومفيش عملية ممكن تفضل عالقة من غير حل** (عكس LeeTaxi).

---

## المرحلة 6.1 — واجهة المحفظة في تطبيق الموبايل (Android) 🟡 (الرصيد + الشحن + الـ PIN اتكتبوا؛ السجل والسحب مع المرحلتين 7 و8)

> **اللي اتكتب:** أيقونة المحفظة جنب الجرس في `HomeHeader` (نفس سلسلة callbacks: `HomeScreen` ← `MainScreen` ← `DorrNavGraph`) + `Routes.WALLET` · `WalletScreen` (Overview: إجمالي / قابل للسحب / "للخدمات فقط" / محجوز، مع شرح كل واحد) · `TopupScreen` (مبلغ ← طريقة دفع ← `quote` حيّ بـ debounce يوضّح الرسوم/الهدية ← PIN ← فتح صفحة البوابة ← polling للحالة كل 3 ثواني ← نجاح/فشل؛ ومسار OTP لـ URPay) · `WalletPinDialog` (مكوّن PIN مشترك: لو مفيش PIN يظهر إدخال+تأكيد مكان التحقق مباشرة — **lazy** — ويستعمل الـ PIN الجديد في نفس العملية) · `WalletPinSettingsScreen` (بند "الرقم السري للمحفظة" في Account: إنشاء أو تغيير بالـ PIN الحالي) · نصوص عربي/إنجليزي.
> **Backend إضافي اتعمل عشان الـ Overview:** `GET {mobile|provider}/v1/wallet` (رصيد دولة الطلب؛ من غير محفظة بيرجّع أصفار مش 404) + اختبار.
> **قرارات:** الأرقام كلها `Long` بالـ minor units وBigDecimal للعرض (مفيش Float)؛ `Idempotency-Key` بيتولّد مرة لكل محاولة ويتجدّد بس بعد رد فعلي من السيرفر (فانقطاع الشبكة يعيد نفس المفتاح آمن)؛ الـ PIN بيتحفظ في الذاكرة لمحاولة واحدة بس (خطوة الـ OTP محتاجاه تاني) ويتمسح عند الانتهاء؛ صفحة البوابة بتتفتح بـ `ACTION_VIEW` وتنتظر تأكيد السيرفر — الشاشة **مبتفترضش نجاح أبداً**.
> ⚠️ **الكود ما اتعملّوش compile ولا اتجرب على جهاز/emulator** — مفيش Android SDK على الجهاز ده (`ANDROID_HOME` بيشاور على مسار مش موجود). اتراجع يدوياً بس؛ أول build ممكن يطلع أخطاء صغيرة.
> **معاينة الويب `http://dorr.test/app/`:** دي معاينة تصميم ثابتة (`public/app/`) منفصلة تماماً عن كود الـ Android — كانت بتعرض رصيد وهمي (`120.50`). اتوصّلت بالـ API الحقيقي: تسجيل دخول فعلي (`/auth/otp` + `/auth/verify`، الكود التجريبي `123456`) وملف جديد `js/wallet.js` فيه الرصيد وشحن المحفظة (مبلغ ← طريقة دفع ← quote ← PIN ← بوابة ← polling) وإعداد الـ PIN، وأيقونة المحفظة في الهيدر. لو الباك إند مش متاح بيرجع لوضع العرض القديم بدون محفظة.
> **مؤجل:** History (مرحلة 7) وWithdraw (مرحلة 8) — مفيش endpoint وراهم لسه، فمش هنحط شاشة فاضية.

**مرجعي فقط** — بيوضّح إزاي شاشات الـ API اللي فوق هتتربط بالتطبيق الفعلي (`androidApp/`، Jetpack Compose). مش مرحلة باك إند مستقلة، بتتنفذ بالتوازي مع المراحل 6–10 أول ما الـ endpoints المقابلة تجهز.

1. **أيقونة المحفظة:** IconButton جديدة جنب `Icons.Rounded.Notifications` في `HomeHeader` (داخل [HomeScreen.kt](../androidApp/app/src/main/java/com/dorr/app/ui/screens/HomeScreen.kt)) — نفس نمط `onOpenNotifications` بالظبط: callback بيتمرر من `HomeScreen` → `MainScreen` → `DorrNavGraph`.
2. **Route جديد:** `Routes.WALLET` في [DorrNavGraph.kt](../androidApp/app/src/main/java/com/dorr/app/navigation/DorrNavGraph.kt)، بيفتح `WalletScreen` (composable جديد).
3. **`WalletScreen`:** تنقّل داخلي بـ enum زي `ProfileSub` في [ProfileScreen.kt](../androidApp/app/src/main/java/com/dorr/app/ui/screens/ProfileScreen.kt) (`WalletSub.OVERVIEW/HISTORY/TOPUP/WITHDRAW`):
   - **Overview:** الرصيد (قابل للسحب + Hold، بوضوح زي ما اتفقنا فصل 10)، وزراير سريعة لشحن/سحب/كشف الحساب.
   - **History:** قائمة `wallet_transactions` (مرحلة 7) بفلاتر.
   - **Topup:** `quote` → اختيار طريقة دفع (مرحلة 5) → `RequiresWalletPin` → تنفيذ (مرحلة 6).
   - **Withdraw:** اختيار/إضافة وسيلة سحب → `RequiresWalletPin` → طلب سحب (مرحلة 8).
4. **PIN في الإعدادات:** بند جديد في `menuItems` بتاعة `ProfileMenuScreen` ([ProfileScreen.kt](../androidApp/app/src/main/java/com/dorr/app/ui/screens/ProfileScreen.kt))، بيفتح `WalletPinSettingsScreen` عبر `SettingsScaffold` — نفس نمط `PersonalDataScreen`.
5. **مكوّن PIN مشترك (Composable واحد يُعاد استخدامه):** إدخال مزدوج (PIN + تأكيد) لأول إنشاء، وإدخال واحد للتحقق وقت أي عملية حساسة. **Lazy:** لو المستخدم دخل عملية محتاجة PIN ومفيهوش، يظهر مسار الإنشاء المزدوج فوراً مكان مسار التحقق، من غير ما يرجع لصفحة تانية.
6. اختبار UI: محاولة شحن/سحب/تحويل من غير PIN مُنشأ → يظهر مسار الإنشاء تلقائي، مش رسالة خطأ.

---

## إغلاق المحفظة على الموبايل (معاينة `/app/`) ✅

> **إعادة تصميم كاملة لشاشات المحفظة** في `public/app/js/wallet.js` + `css/wallet.css` (واجهة "تطبيق داخل التطبيق" بتفتح فوق الفريم بانتقالات حقيقية)، متجرّبة فعلياً في Chrome headless على الـ API الحقيقي (لقطات شاشة لكل شاشة):
> - **الرئيسية:** كارت رصيد بتدرّج وتأثير لمعة، عدّاد رقم بيتحرك (count-up)، إخفاء الرصيد بأيقونة العين (بيتحفظ)، شريحتين قابل للسحب/للخدمات فقط (بيفتحوا شرح لكل نوع رصيد)، أزرار سريعة بأيقونات، آخر الحركات، أرصدة الدول التانية، skeleton أثناء التحميل وحالة فاضية.
> - **الشحن:** إدخال مبلغ كبير + مبالغ سريعة، طرق دفع ككروت بأيقونات، ملخص الرسوم/الهدية بيظهر بحركة، زرار "ادفع X"، شيت PIN بلوحة أرقام ونقاط (اهتزاز عند الغلط، إنشاء الـ PIN مرتين لأول مرة)، **بوابة الدفع داخل الفريم كمتصفح داخلي**، انتظار بنبض متحرك، ثم شاشة نجاح (علامة ✓ بترسم نفسها + كونفيتي + رصيدك الآن)، وفشل بـ ✗ متحركة.
> - **التحويل / الكشف / الـ PIN:** نفس اللغة البصرية — الكشف مجمّع باليوم بفلاتر وأيقونة لكل نوع حركة وتفاصيل الحركة في شيت، والـ PIN بخطوات (الحالي ← الجديد ← تأكيد).
> - **Home + Account:** كارت الرصيد في تبويب الحساب اتغيّر لنفس التصميم (شحن + تحويل)، وشريط رصيد صغير في الرئيسية.
> - **Sandbox gateway (dev فقط):** بنك وهمي (`SandboxGateway`) عشان الدورة كاملة تشتغل من غير credentials حقيقية. **بيتفعّل بس في `local`/`testing`** (`wallet.sandbox_enabled`، وممكن `WALLET_SANDBOX_ENABLED`)، صفحاته بترجّع 404 لو مقفول، ومش بيتزرع في production. **مبيتخطاش الأمان:** الـ redirect بيرجع بـ reference بس، والمحفظة بتتأكد من "البنك" server-side قبل ما تضيف أي حاجة (اختبار: callback مزوّر بيتحرك بدون أي رصيد).
> - **ثغرة اتقفلت أثناء كده (كل البوابات):** الـ callback كان بيصدّق أي دفعة مؤكدة من البوابة حتى لو تبع **دفعة تانية** — حد يدفع فاتورة صغيرة ويعيد استخدام الـ reference على callback دفعة تانية (أو يخلّي دفعة حقيقية واحدة تضيف رصيد مرتين). دلوقتي الـ reference الراجع لازم يطابق `gateway_reference` بتاع نفس الدفعة (`reference_mismatch`، والدفعة بتفضل pending). فيه اختبار.
> - ⚠️ **Android لسه ما اتعمللوش إعادة تصميم ولا compile** — التصميم الجديد في المعاينة بس.
## قفل المحفظة بالـ PIN + شريط التبويبات (معاينة `/app/` + Android)

> - **كل دخول للمحفظة بيطلب الـ PIN:** شاشة قفل بلوحة أرقام قبل أي شاشة محفظة (أيقونة الهيدر، كارت الحساب، أزرار شحن/تحويل). لو مفيش PIN بيظهر إنشاء (إدخال + تأكيد) في نفس المكان وبعدها المحفظة تفتح. **أي خروج بيقفلها من جديد:** سهم الرجوع، أو أي تبويب في الشريط السفلي، أو تسجيل الخروج — والدخول التاني بيطلب الـ PIN تاني.
> - Endpoint جديد `POST {mobile|provider}/v1/wallet/pin/verify` (خلف `RequiresWalletPin` فبيعدّ المحاولات ويقفل بعد 5 غلطات — اختبارات). الاستثناء الوحيد: شاشة "الرقم السري للمحفظة" في الإعدادات (بتتحقق من الحالي بنفسها).
> - **الشريط السفلي ظاهر ومضغوط في كل شاشات المحفظة** (طبقة المحفظة بتقف فوقه؛ الزرار العائم كمان ظاهر)، والضغط على أي تبويب بيخرج من المحفظة.
> - الـ PIN لسه بيتطلب **كمان** عند كل عملية دفع/تحويل (قرار سابق: الفتح لوحده مش كفاية لتحريك فلوس).
> - Android: `WalletPinGate` بنفس المنطق (متكتب، مش متجرّب — مفيش SDK). **الشريط السفلي في Android لسه مش ظاهر تحت شاشة المحفظة** (هي route مستقل مش جوه `MainScreen`).
## شاشات الأدمن (Vue) للمحفظة ✅

> قسم **"المحفظة"** جديد في الشريط الجانبي (كل بند بيظهر بس لصاحب صلاحية `.view` بتاعته)، بـ 7 شاشات على نفس تصميم لوحة التحكم (Bootstrap/Ynex)، وعربي + إنجليزي. متجرّبة في Chrome headless بحساب الأدمن على بيانات حقيقية:
> - **المحافظ** (`/admin/wallet/wallets`): بحث بالاسم/التليفون/الإيميل، تفاصيل المحفظة + كشف حسابها بفلاتر، **تسوية يدوية** (نوع الرصيد + السبب إجباريين).
> - **المعاملات الإلكترونية**: ملخص لكل عملة، فلاتر، تفاصيل الدفعة بسجل البوابة كامل (طلب/رد لكل حدث) وحركات المحفظة الناتجة، وأزرار **تسوية مع البوابة / استرجاع** بخطوة تأكيد.
> - **طلبات السحب**: الافتراضي "قيد المراجعة"، بيانات التحويل الكاملة (IBAN…) في تفاصيل الطلب بس، **موافقة بإيصال إجباري** أو **رفض بسبب**، وتحميل الإيصال (بالـ token لأنه على disk خاص).
> - **الحسابات المالية**: ملخص دخل/مصروف/صافي لكل عملة + جدول بفلاتر (قراءة فقط).
> - **طرق الدفع**: إضافة/تعديل/حذف/تفعيل، ترجمات كل اللغات، حقول بيانات البوابة حسب نوعها (بتُخزّن مشفّرة ومبتترجعش)، وربط الدول بحد أدنى/أقصى.
> - **الرسوم والهدايا**: إضافة/تعديل/حذف، النسبة بتأكيد مزدوج، سقف إجباري للهدية، ميزانية وفترة وأولوية.
> - **إعدادات المحفظة**: حدود السحب والتحويل وحد الدين لكل دولة (247 دولة → فيه بحث)، والدين بيتعرض كـ "دين مسموح" موجب وبيتخزّن سالب.
> - **إصلاحات backend اتعملت عشان الشاشات:** تعديل طريقة دفع بدون إعادة كتابة الـ credentials بيحتفظ بالمخزّن (كان بيطلبها كل مرة)، وبوابة `sandbox` مش بتطلب credentials (+ اختبارين).
> - **ملف موجود قبلي كان بيكسر `npm run build` لكل المشروع:** `views/notifications/index.vue` (غير متتبَّع في git) كان import الـ axios بمسار غلط — صلّحته (سطر واحد).
> - الحاجة الوحيدة اللي مش متجرّبة: زرار Reconcile/Refund (متغطّي باختبارات الـ API)، والأدمن بالعربي (الترجمات موجودة، ما اتصورتش).
## المرحلة 7 — كشف الحساب وشاشات الأدمن ✅ (Backend + الموبايل + معاينة الويب؛ شاشات Vue للأدمن لسه)

> **اتنفّذ (137 اختبار ناجح، منهم 15 جدد في `tests/Feature/WalletStatementTest.php`):**
> - `GET {mobile|provider}/v1/wallet/transactions` — كشف حساب المالك لدولة الطلب فقط (مفيش id بيجي من العميل)، newest-first، فلاتر `from/to/type/bucket/direction`، ترقيم صفحات. كل صف فيه `bucket` و`balance_after` و`type_label` و`note` **مترجمين وقت القراءة** (الملاحظات متخزنة كـ key + variables فالسجل بيتبع لغة المشاهد مش لغة اللي عمل الحركة). مالك من غير محفظة = قائمة فاضية مش خطأ.
> - `GET wallet` بقى بيرجّع `other_wallets` (أرصدة الدول التانية، للقراءة فقط).
> - أدمن: `GET wallets` (بحث باسم/تليفون/إيميل المالك — polymorphic فالبحث بيتم على جدول كل نوع — + فلتر بلد)، `GET wallets/{id}`، `GET wallets/{id}/transactions`، **`POST wallets/{id}/adjustments`** (تسوية يدوية: `bucket` + `direction` + `reason` إجباريين؛ صف ledger منسوب للأدمن + قيد `manual_adjustment_expense/income` في الحسابات المالية **في نفس الـ transaction** — فشل أي واحد بيرجّع الاتنين).
> - أدمن: `GET financial-entries` + `financial-entries/summary` (قراءة فقط، دخل/مصروف/صافي **لكل عملة** ومش بيتجمع بين عملات) — مفيش create/update/delete أصلاً: القيود بتتكتب بس من العمليات اللي بتسببها.
> - صلاحيات جديدة: `wallets.{view,manual-adjustment}` و`financial-entries.view` (بجانب `wallet-fee-rules.*` و`online-transactions.*` من المرحلة 6) — شغّل `AdminPermissionSeeder` عشان الـ super-admin ياخدها.
> - الموبايل: `HistoryScreen` (Android — فلاتر الكل/وارد/صادر/للخدمات فقط، عرض المزيد، والصف اللي حرّك رصيد "للخدمات فقط" بيتعلّم) + نفس الشاشة في معاينة `/app/`. ⚠️ كود Android لسه ما اتعملّوش compile (مفيش SDK).
> - **لسه:** شاشات Vue في لوحة الأدمن (الـ API كله جاهز وراها) — مؤجلة.

1. Endpoint كشف حساب (User/Provider): قائمة `wallet_transactions` بفلاتر تاريخ + عرض `bucket` بوضوح (إجمالي / قابل للسحب / Hold) لكل صف.
2. Endpoint رصيد المحفظة الحالي (حسب دولة السياق) — يوضح لو فيه محافظ تانية بدول تانية (Read-only).
3. شاشة أدمن: كل المحافظ + بحث بالمالك/الدولة + تفاصيل حركات أي محفظة + زرار "تسوية يدوية" (Bucket إجباري + سبب).
4. صلاحيات (`spatie/laravel-permission`): `wallet transaction read`, `wallet manual adjustment`, `wallet fee rule manage`, `financial ledger read`, `online transaction read`, `online transaction reconcile`... (حسب قرار المرحلة 0).

**Deliverable:** شفافية كاملة للمستخدم والأدمن على كل قرش في المحفظة.

---

## المرحلة 8 — السحب (Provider) ✅ (Backend + Admin API؛ مفيش واجهة لأن مفيش تطبيق Provider لسه)

> **اتنفّذ (160 اختبار ناجح، منهم 23 جدد في `tests/Feature/WithdrawalTest.php`):**
> - Migration `withdrawal_methods` + `withdrawal_requests`. **إضافتين على التصميم:** `idempotency_key` (unique) + `request_hash` على الطلب — نفس حماية الضغط المزدوج في باقي العمليات المالية — فضغطتين على "اسحب" مش هتفتحوا طلبين.
> - `WithdrawalService` طبقة رقيقة فوق `WalletService`: **الطلب** بيحجز المبلغ فوراً (`hold` على `withdrawable` بس، `expires_at=null`) من غير أي حركة رصيد ولا صف ledger؛ **القبول** = `capture` لصف `withdrawal` حقيقي (منسوب للأدمن ومربوط بالطلب) + إيصال إجباري؛ **الرفض** = `release` + سبب إجباري. الـ spend_only عمره ما بيوصل لحجز (اختبار: 10,200 مرفوضة رغم إن إجمالي المحفظة 10,500).
> - طلب واحد `pending` لكل محفظة: بيتأكد تحت `lockForUpdate` على المحفظة فضغطتين متزامنتين ما يعدّوش الفحص الاتنين.
> - حدود `min/max_withdrawal_minor` من `wallet_settings` للدولة؛ **صف الإعدادات ناقص = رفض (fail closed)** مش قيمة افتراضية صامتة.
> - **PIN** إجباري على: إنشاء طلب، إضافة وسيلة، تعديل وسيلة. الحذف والتفضيل من غيره (مش بيوجّهوا فلوس لمكان جديد).
> - وسيلة السحب: `data` مشفّرة at-rest و**المالك بيرجعله شكل مقنّع بس** (`Al Rajhi ••7519`)، الأدمن بيشوف التفاصيل الكاملة **في عرض الطلب الواحد بس، مش في القائمة**؛ التعديل بيستبدل `data` كلها. كل استعلام مقيّد بالمالك في الكود — id وسيلة/طلب لحد تاني = 404.
> - **الإيصال على disk خاص (`local`) مش `public`** (فيه بيانات بنكية)، وبيتحمّل من endpoint بيتحقق من الصلاحية: الأدمن (`withdrawal-requests.view`) أو صاحب الطلب بس.
> - Endpoints: `provider/v1/wallet/withdrawal-methods` (CRUD + `PATCH .../favorite`)، `provider/v1/wallet/withdrawals` (+ `/{id}` + `/{id}/receipt`)، `admin/v1/withdrawal-requests` (+ show/receipt/approve/reject). صلاحيات جديدة: `withdrawal-requests.{view,approve,reject}`.
> - ⚠️ الحاجة اللي **مش** اتعملت: **مفيش شاشة**. تطبيق Android ومعاينة `/app/` بتوع **User** (`mobile/v1`) والسحب Provider-only حسب التصميم؛ تطبيق الـ Provider نفسه لسه ما اتبناش. ومفيش "إلغاء الطلب" من المالك (الـ enum فيه pending/approved/rejected بس) — لو عايزه بنضيف حالة `cancelled` تعمل `release`.

1. Migrations: `withdrawal_methods`, `withdrawal_requests` (جداول 6، 7؛ `withdrawal_requests.hold_id → wallet_holds`).
2. `WithdrawalService` — **طبقة رقيقة فوق `WalletService::hold/capture/release` من المرحلة 2**، مفيش منطق حجز مكرر هنا:
   - إنشاء طلب → `hold(wallet, amount, bucket: withdrawable, reference: withdrawal_request, expiresAt: null)`.
   - قبول → `capture(hold, amount, type: withdrawal)` + إيصال إجباري عبر Media.
   - رفض → `release(hold, reason)` + `rejection_reason` إجباري.
3. **`RequiresWalletPin` على Route إنشاء طلب السحب** (وعلى إضافة/تعديل وسيلة سحب — محسوم، فصل 1.2).
4. Endpoints Provider: إدارة وسائل السحب (CRUD + تفضيل)، إنشاء طلب سحب، عرض طلباته.
5. Endpoints Admin: قائمة الطلبات + قبول/رفض.
6. اختبار: طلب سحب بيحجز المبلغ فوراً (مش وقت القبول بس)، ومحاولة سحب تاني وهو عنده طلب `pending` بترفض، وطلب سحب من غير PIN صحيح بيترفض، وإضافة/تعديل وسيلة سحب من غير PIN بيترفض.

**Deliverable:** مقدم الخدمة يقدر يسحب أرباحه فعلياً بإجراء أدمن موثّق.

---

## المرحلة 9 — الأهلية لطلب/استقبال الخدمات (`WalletEligibilityService`) ✅ (الخدمة + الإعدادات + endpoint؛ الربط بالطلبات لما موديولها يتبني)

> **اتنفّذ (175 اختبار ناجح، منهم 15 جدد في `tests/Feature/WalletEligibilityTest.php`):**
> - `WalletEligibilityService` — **مكان واحد** بيقرر الحد للاتنين: `eligible ⇔ الإجمالي (withdrawable + spend_only) ≥ min_allowed_balance_{provider|user}` من `wallet_settings` بتاعة الدولة. الحد **signed** (`-10000` = دين لحد 100.00، `0` = ممنوع أي دين). الرصيد المحسوب هو رصيد **دولة الخدمة** بس (دين السعودية مبيوقفش مصر). الـ `spend_only` بيتحسب في الإجمالي (اختبار: −30 قابل للسحب + 50 هدية = +20 → مسموح).
> - **Fail closed:** صف الإعدادات ناقص = مش مؤهل بـ `reason=settings_missing`، مفيش قيمة افتراضية صامتة.
> - `check()` بيرجّع `EligibilityResult` فيه `shortfall_minor` = **بالظبط اللي ناقص** ("رصيدك X، اشحن Y") ، و`assertEligible()` بيرمي `NotEligibleException` (403 + `wallet_not_eligible` + الأرقام). وقت التأكيد النهائي بيتمرر له الـ wallet المقفول عشان الفحص يتم على الرصيد الحالي مش القديم (سباق — اختبار بيثبته).
> - `filterEligibleProviders($query, $country)` للإرسال: **شرط SQL واحد على الأرصدة المخزّنة، مفيش حلقة على المزوّدين**، ومتأكد بالاختبار إنه بيطابق `check()` لكل مزوّد (بما فيهم اللي مالهمش محفظة بعد، اللي رصيدهم صفر يتحسب).
> - `GET {mobile|provider}/v1/wallet/eligibility` — عشان التطبيق يشرح سبب الإيقاف وكام يشحن بدل ما يفشل بس.
> - شاشة إعدادات المحفظة للأدمن (API): `GET wallet-settings`، `GET/PUT wallet-settings/{country}` (بتعدّل بس — الصف بيتعمل تلقائي مع الدولة). الحد الدين **ممنوع يبقى موجب** والـ max لازم ≥ min. صلاحيات `wallet-settings.{view,update}`.
> - ⚠️ **لسه مربوطة بأي مسار حقيقي؟ لأ** — مفيش موديول طلبات/حجوزات في dorr. الخدمة جاهزة ومتجربة، والربط (`filterEligibleProviders` وقت الإرسال، `assertEligible` وقت إنشاء/قبول الطلب وتاني وقت التأكيد جوه نفس القفل) بيتعمل مع أول موديول خدمة. ومفيش endpoint "غرامة" للأدمن — الغرامة (`Penalty` سالبة في `withdrawable`) بتيجي من الإلغاء المتكرر في موديول الطلبات؛ لحد ده التسوية اليدوية بتعمل نفس الأثر.

**تتنفذ لما موديول الطلبات/الحجوزات يبدأ فعلياً** (حالياً مفيش طلبات في dorr، حسب `wallet-plan.md § 6`).

1. `WalletEligibilityService::canRequestService(owner, country, category)` — بيقرأ `wallet_settings.min_allowed_balance_*` ويقارنه بإجمالي رصيد المحفظة.
2. دمجها في مسار قبول الطلب (Provider) وإنشاء الطلب (User) — فحص وقت الإرسال **ووقت التأكيد النهائي** (سباق).
3. رسالة واضحة للمستخدم/المزوّد لما يكون موقوف: "رصيدك X، محتاج تشحن Y عشان تكمل".

**Deliverable:** حد الدين شغال فعلياً على أول موديول خدمة يتبني.

---

## المرحلة 10 — التحويل بين المستخدمين ✅ (Backend + الموبايل + معاينة الويب)

> **اتنفّذ (194 اختبار ناجح، منهم 19 جدد في `tests/Feature/WalletTransferTest.php`):**
> - `POST mobile/v1/wallet/transfers` (User فقط — مفيش route لـ provider) خلف `RequiresWalletPin` + `Idempotency-Key`. المستلم بيتحدد بـ `dial_code` + `phone` (نفس شكل تسجيل الدخول) وبنفس دولة المُرسِل.
> - `TransferService` بيقرر **هل مسموح** بس، والحركة نفسها `WalletService::transfer()`: المستلم **دايماً `spend_only`** (اختبار المثال الأصلي: شحن 1000 ← تحويل 500 ← المستلم يصرف مش يسحب ← لو رجّعها، ترجع للمُرسِل `spend_only` مش `withdrawable`).
> - القواعد: `transfers_enabled` (مقفول افتراضياً في كل دولة) · حد لكل عملية / يومي / شهري — **الإجمالي بيتحسب من الـ ledger نفسه (`transfer_out`) تحت قفل المحفظة**، مفيش عدّاد ممكن يتنافر، والرسالة بتقول كام لسه متاح · صف الإعدادات ناقص = رفض (fail closed).
> - **المتاح مش الرصيد:** `WalletService::debit` بيحمي `spend_only ≥ 0` بس ومش بيبص على الحجوزات، فالتحويل بيتأكد بنفسه من `balance − held` — مينفعش يلمس فلوس محجوزة لسحب. مفيش تحويل من دين. من غير اختيار، الهدية (`spend_only`) بتتصرف الأول؛ والمُرسِل ممكن يحدد `from_bucket`.
> - **الأقفال:** المحفظتين بيتقفلوا **بترتيب الـ id قبل أي قراءة** (حد يومي بيتقرأ بعد القفل + منع deadlock لو اتنين بيبعتوا لبعض في نفس اللحظة).
> - **Idempotency:** retry بنفس المفتاح بيرجّع نفس التحويل من غير ما يتحرك فلوس تاني **ومن غير إعادة فحص الرصيد** (الأصلي صرفه)؛ نفس المفتاح لمستلم/مبلغ مختلف = 409 (الـ hash فيه رقم المستلم).
> - **مستلم غلط = رد واحد لكل الأسباب** (مش موجود / دولة تانية / محظور / نفسك) ومحفظة مبتتعملش لحد مش موجود — فورم التحويل مينفعش يبقى وسيلة لمعرفة مين عنده حساب.
> - كشف الحساب بقى فيه `counterparty` (الاسم + التليفون **مقنّع**) لصفوف التحويل، عند الطرفين.
> - `GET wallet` بقى بيرجّع `dial_code` للدولة. الموبايل: `TransferScreen` (Android) + شاشة "تحويل لعميل" في `/app/`. ⚠️ كود Android لسه ما اتعملّوش compile.
> - **مش متغطي:** اختبار سباق حقيقي بعمليات متزامنة على MySQL (اللي في الاختبارات تسلسلي على SQLite).

### 10.1 — إعادة تصميم التحويل: رقم المحفظة + تأكيد المستلم قبل الإرسال ✅ (33 اختبار في `WalletTransferTest`، 218 في الـ suite كله)

> **بديل للتحويل بـ `dial_code + phone` (اتشال):** التحويل بقى على خطوتين — مفيش تحويل من غير ما المُرسِل يشوف المستلم الأول.
> - **رقم المحفظة (`wallets.wallet_number`):** 11 رقم = 10 عشوائي + خانة تحقق Luhn، unique، بيتولّد في `Wallet::booted()` (والمحافظ القديمة اتعمل لها backfill). بيتعرض `630 7326 2493`. **لكل محفظة رقم** — اللي عنده محفظة في كل دولة عنده رقم لكل واحدة، فالفلوس بتنزل في المحفظة المقصودة بالظبط (`Support/WalletNumber.php`).
> - **الخطوة 1 — `POST mobile/v1/wallet/transfers/lookup`** (بدون PIN، `throttle:20,1`): `mode=phone|wallet` + `phone` أو `wallet_number`. بيرجّع الاسم **مقنّع** (`MaskedName`: أول حرف من كل كلمة + `***` → `س*** أ***`)، والتليفون كامل لو البحث بالتليفون، أو رقم المحفظة لو بالرقم، + دولة/عملة المحفظة + `recipient_token` (مشفّر وموقّع، صلاحيته 600 ث، مربوط بالمُرسِل والدولة والمستلم والمحفظة).
> - **الخطوة 2 — `POST mobile/v1/wallet/transfers`** (PIN + `Idempotency-Key`): `recipient_token` + `amount_minor` (+ `from_bucket`). التوكن منتهي/متلاعب فيه/لمُرسِل تاني ← رفض (`transfer_recipient_expired`).
> - **الدولة:** التحويل دايماً داخل دولة محفظة المُرسِل (محفظة سعودية ← أرقام ومحافظ سعودية فقط). محفظة في دولة تانية أو محفظتك نفسك أو غير نشطة ← نفس رد "المستلم غير متاح" (مفيش تسريب لمين عنده حساب). البحث بالتليفون **مبيكشفش رقم محفظة المستلم**.
> - **فالديشن التليفون حسب الدولة (`TransferRecipientResolver::nationalPhone`):** طول + بادئة من `countries.phone_length/phone_starts_with`، بيقبل `05…` و`+9665…` و`9665…` والأرقام العربية-الهندية. `GET wallet` بقى بيرجّع `phone_length`, `phone_starts_with`, `wallet_number`, `wallet_number_formatted`، وبيعمل المحفظة (lazy) أول ما تتفتح الشاشة عشان الرقم يبقى موجود.
> - **كشف الحساب:** اسم الطرف التاني بقى مقنّع دايماً (`MaskedName`). الأدمن: `WalletResource` بيرجّع `wallet_number` والبحث في المحافظ بيدعم رقم المحفظة.
> - **معاينة `/app/`:** شاشة التحويل: بانر الدولة، تبويب منزلق (رقم الهاتف / رقم المحفظة)، فالديشن لحظي (✓ / ✕ + رسالة)، شاشة تأكيد (شارة "تحقق من المستلم"، أفاتار، الاسم المقنّع، الرقم، دولة/عملة، المتاح، ملاحظة إن المبلغ للخدمات فقط) ← PIN ← نجاح بـ confetti. الهوم فيه كارت "رقم محفظتك" + زر نسخ.
> - **Android (اتحدّث، ⚠️ لسه ما اتعملّوش compile — مفيش Android SDK على الجهاز ده):** `WalletApi` (`transferLookup` + `TransferRecipientDto` + `TransferRequest(recipient_token, amount_minor)`)، `TransferScreen` على خطوتين (تبويب هاتف/محفظة، فالديشن حسب الدولة + Luhn، شاشة تأكيد بالاسم المقنّع، انتهاء التوكن بيرجّع للبحث)، وكارت "رقم محفظتك" + نسخ في `WalletScreen`، وstrings عربي/إنجليزي.
> - **الأدمن (Vue):** جدول المحافظ فيه عمود "رقم المحفظة" (البحث برقمها شغّال). اتجرّب بالعربي.

### 10.2 — QR + إصلاحات الاختبار اليدوي + البوابات (coming soon) ✅

> - **التحويل بالتليفون كان بيرفض حسابات موجودة:** البحث كان بيشترط `users.country_id = دولة المحفظة`، والعمود ده بيتملّى بس بعد استكمال البروفايل (مستخدمو الـ OTP عندهم `null`). دلوقتي الدولة بتتحدد من رمز الاتصال في الرقم نفسه (+966) مش من العمود. لو المُرسِل كتب **رقمه هو** بيتقاله صراحةً `transfer_to_self` ("هذا رقمك أنت") بدل "لم نجد حساباً".
> - **التحويل بـ QR:** `GET wallet` بيرجّع `qr_payload` = `dorr://wallet/SA/12345678901` (حقائق عامة بس: الرقم والدولة — مسحه بيفتح شاشة تأكيد المستلم زي كتابة الرقم بالظبط، مش بيحرّك فلوس ولا بيكشف رصيد). `POST transfers/lookup` بقى بيقبل `mode=qr` + `qr`؛ الحالات: نص مش رمز محفظة/رقم غلط الـ check digit ← خطأ حقل `qr`، QR لمحفظة دولة تانية ← `transfer_qr_other_country` برسالة دقيقة، QR بتاعي ← `transfer_to_self`. معاينة `/app/`: صفحة "رمز QR الخاص بي" (نسخ الرقم، حفظ PNG، مشاركة) + شاشة مسح (كاميرا مباشرة على https، وعلى http بيستخدم "التقاط صورة" / "من المعرض" لأن المتصفح بيمنع الكاميرا على http)؛ المكتبات محلية في `public/app/js/vendor/` (`qrcode-generator` MIT + `jsQR` Apache-2.0). اتجرّب: الرمز اللي بيتولّد بيتقرا صح والمسح بيوصّل لشاشة تأكيد "س*** أ***".
> - **البوابات:** `PaymentMethod::isConfigured()` (المفاتيح المطلوبة لكل بوابة) ← الـ Resource بيرجّع `coming_soon`، والـ topup/quote بيرفض `payment_method_coming_soon`. الـ Seeder بقى يعرض الـ 3 بوابات (مفعّلة ومربوطة بالسعودية) وما بيمسحش اللي الأدمن غيّره ولا بيكتب credentials فاضية فوق اللي اتدخلت من الداشبورد. **MyFatoorah:** بيانات الاختبار من LeeTaxi (`apitest.myfatoorah.com`) في `.env` المحلي — ⚠️ الـ token القديم ده بيرجّع **401** من MyFatoorah (منتهي/اتغيّر) فلازم token جديد من الداشبورد. **ARB/URPay:** بدون credentials ← ظاهرين في الموبايل بشارة "قريباً" ولمّا تدوس عليهم بيظهر "ستتوفر قريباً" (Android برضه).
> - **MyFatoorah جرّبناه فعلياً على `apitest` بمفتاح الاختبار (KWT):** اتصلّحت 3 حاجات كانت بترفض الطلب أو هترفض الدفع: (1) `CustomerName` إجباري ← fallback "Customer" لمّا المستخدم ملوش اسم، (2) `CustomerMobile` أقصى 11 حرف ← بنبعت الرقم بدون رمز الدولة + `MobileCountryCode` منفصل (`GatewayChargeRequest::nationalPhone()`)، (3) **التحقق من المبلغ**: `InvoiceValue` بيرجع بعملة حساب التاجر (KWD ← 0.81 لفاتورة 10 SAR) فكان أي دفع هيتحسب "amount_mismatch" ← بنقرا `InvoiceDisplayValue` ("10.000 SR") الأول. كمان `NotificationOption=LNK` بدل SMS. اتجرّب: إنشاء الفاتورة وفتح رابط `demo.MyFatoorah.com` واستعلام الحالة؛ **الدفع بالكارت نفسه على صفحة MyFatoorah ما اتجرّبش آلياً**.
> - **مشكلة حقيقية اتصلّحت:** عمود `payment_methods.credentials` كان `json` في MySQL بينما الـ cast `encrypted:array` بيخزّن نص مشفّر ← حفظ أي credentials كان بيفشل على MySQL (SQLite في الاختبارات ما بيتحقّقش من JSON فما اتلحظش). Migration جديدة بتحوّله لـ `text`.
> - **404 في الشحن التجريبي:** صفحة البنك الوهمي كانت بتخزّن حالة الدفع في الـ cache بس (TTL ساعة) وبترد JSON 404 لو راحت. دلوقتي لو الدفع لسه pending بتتبني الحالة من صف الدفع نفسه، ولو مفيش فعلاً بتظهر صفحة مفهومة بدل JSON.

### 10.3 — الإشعارات (متعددة اللغات) ✅

> **القنوات (زي Jawad: Pusher + OneSignal):** `App\Services\Notifications\NotificationCenter::send()` هو المكان الوحيد اللي بيحوّل حدث لإشعار: (1) **قائمة داخل التطبيق** (جدول `notifications`) بتتخزن **مفتاح الترجمة + المتغيرات مش جملة جاهزة**، فمين يفتح القائمة يشوفها بلغته هو وقت القراءة (`X-Locale`)؛ (2) **لحظي** Pusher/Soketi بيتكتب بلغة **المستلم** (عمود `locale` الجديد على users/providers/admins بيتحدّث تلقائياً بـ middleware `remember-locale`) مش لغة اللي عمل الحدث؛ (3) **Push** OneSignal (والتطبيق مقفول) بيتبعت **مرة واحدة بكل اللغات** (`contents`/`headings` = `{ar, en}`) وOneSignal بيختار حسب لغة كل جهاز. الإرسال بعد الـ commit (`DB::afterCommit`) فمعاملة اترجعت ما تبعتش إشعار، والـ push بيتنفّذ بعد الرد (`defer`)، وأي عطل في الإشعارات ما بيوقفش الفلوس (اختبار: OneSignal راجع 500 والتحويل بيتم).
> - **الأحداث (`WalletNotifier`):** شحن نجح (مع/بدون هدية) · شحن فشل · شحن اتلغى/refund · تحويل مُرسَل (للمُرسِل) + مستلَم (للمستلم — كل واحد بيشوف الاسم **المقنّع** للتاني بس) · إنشاء/تغيير الـ PIN · **قفل المحفظة بعد محاولات PIN غلط** (تنبيه أمني) · طلب سحب (لمقدّم الخدمة + للأدمنز اللي ليهم `withdrawal-requests.approve` وsuper-admin) · سحب اتدفع · سحب اترفض (بالسبب) · تعديل يدوي من الإدارة (+/− بالسبب). مفيش PIN ولا بيانات دفع ولا رقم كامل في أي إشعار.
> - **الترجمة:** `lang/{ar,en}/notifications.php` (مفتاح `{event}_title`/`{event}_body`). لغة جديدة = ملف `lang/{code}/notifications.php` + إضافة الكود في `LocaleResolver::supported()`؛ الناقص بيرجع للإنجليزي.
> - **API للموبايل (user + provider):** `GET notifications` (`?unread=1`) · `GET notifications/unread-count` · `POST notifications/{id}/read` · `POST notifications/read-all` · `POST/DELETE notifications/devices` (تسجيل OneSignal player id؛ نفس الجهاز لو اتسجّل بحساب تاني بينتقل). كل صف فيه `event` + `data` (ids للـ deep link) + `created_at_iso`.
> - **الداشبورد (كان ناقص):** route `admin.notifications.index` ما كانش موجود (زر "عرض الكل" كان بيضرب)، ومفاتيح `notifications.*` في `en/ar.json` ما كانتش موجودة (كانت بتظهر الأسماء الخام)، وبيانات الحدث ما كانتش بتتخزن في الـ DB. اتصلّحوا + كل إشعار بقى ليه أيقونة حسب الحدث ورابط "فتح" للشاشة المناسبة (طلبات السحب/المعاملات). الجرس بيستقبل اللحظي لو Pusher/Soketi شغال (`BROADCAST_CONNECTION=pusher` + مفاتيح `.env`).
> - **معاينة `/app/`:** جرس الهوم بقى بيجيب الإشعارات الحقيقية (شارة غير مقروء، قراءة واحد/الكل، "فتح المحفظة")، بيتحدّث كل 20 ث وبعد أي حركة فلوس، وبيعيد التحميل لما اللغة تتغيّر.
> - ⚠️ **مش متجرّب:** بث Pusher الحقيقي وOneSignal الحقيقي (مفيش مفاتيح على الجهاز — متجرّب بـ `Http::fake` ومسار `log`)، وتسجيل الـ player id من تطبيق Android (محتاج OneSignal SDK — مش متعمل).

### 10.4 — تصميم المحفظة في تطبيق Android = نفس تصميم المعاينة ✅ (compile + رسم الشاشات اتعمل، مش متجرّب على جهاز)

> **القاعدة من هنا ورايح:** الشغل على التطبيق (`androidApp/`)، والمعاينة `public/app/` ما بتتلمسش إلا لو الحاجة موجودة فعلاً في التطبيق.
> - اتنقل تصميم المعاينة كله لـ Compose بنفس الألوان (أحمر `#E50914`) والكروت والـ tone wells والحركات: `WaTheme.kt` (نظام التصميم + `waRise` + shimmer)، `WaPin.kt` (keypad + نقط + اهتزاز)، `WaSheets.kt` (شيتات مع backdrop)، `WaStatus.kt` (seal بيترسم + confetti + pulse)، `WaAmount.kt`، والصفحات: `WalletHome/Topup/Transfer/TransferConfirm/Qr/History/PinSettings`، و`WalletScreen.kt` (بوابة الـ PIN + stack بصفحات بتنزلق حسب RTL + toast). `WalletHost` = الحالة المشتركة.
> - **شريط التبويبات ظاهر في كل صفحات المحفظة:** المحفظة بقت جوّه `MainScreen` فوق المحتوى وتحت الشريط (مش route منفصل)، وأي تبويب بيقفلها (= بيعمل lock تاني).
> - **ميزات كانت في المعاينة بس وبقت في التطبيق:** رقم المحفظة + QR (`zxing`، مسح بالكاميرا `zxing-android-embedded` أو من صورة)، تحويل بـ lookup + اسم مقنّع + فالديشن حسب الدولة + Luhn، "قريباً" للبوابات اللي ملهاش credentials، الشحن بالـ sandbox داخل WebView، وشاشة الإشعارات بقت على الـ API الحقيقي (`NotificationApi`).
> - **الاختبار البصري:** رسم كل الشاشات بـ Paparazzi (بالعربي RTL) ومراجعتها بالعين؛ ده اكتشف وصلّح: أسماء الدول بالإنجليزي، ترتيب علامات الأرقام في RTL، أرقام مقنّعة معكوسة. الكود اللي فيه `LocalInspectionMode` بيعرض الحالة النهائية للحركات (للمعاينة/الاختبار بس).
> - ⚠️ **لسه مش متجرّب على جهاز/محاكي:** اللمس والكيبورد، الكاميرا الحقيقية للـ QR، الـ WebView للـ sandbox، الحركات وهي شغالة، وتسجيل OneSignal player id (محتاج SDK).

1. Endpoint تحويل (User ↔ User، حسب `wallet_settings.transfers_enabled`): تحقق دولة موحّدة + حدود يومي/شهري/لكل عملية، خلف `RequiresWalletPin`.
2. استخدام `WalletService::transfer()` من المرحلة 2 (المستلم `spend_only` دايماً — مفيش parameter، وقفل المحفظتين بترتيب الـ `id`).
3. اختبار الحدود اليومية/الشهرية بالتوازي مع سباق متزامن، واختبار PIN إجباري قبل التنفيذ.

**Deliverable:** تحويل فعلي بين مستخدمين بنفس قواعد فصل 10.

---

## المرحلة 11 — الدفع من المحفظة (بعد موديول الطلبات/الحجوزات)

**القرار بيتحدد لكل خدمة على حسب طبيعة سعرها (محسوم: فيه الاتنين، فصل 2.1):**
- **سعر ثابت** (متفق عليه قبل الدفع): `WalletService::debit()` مباشر — نفس المنطق تحت.
- **سعر تقديري** (بيختلف النهائي عن التقديري، زي رحلة أو خدمة بالساعة): `hold()` وقت إنشاء الطلب (بالسعر التقديري) → `capture()` بالمبلغ الفعلي بعد التنفيذ (الفرق بيترجع متاح تلقائي، من غير أي حركة استرجاع منفصلة) → `release()` لو الطلب اتلغى قبل التنفيذ.

1. ترتيب الخصم `spend_only` ثم `withdrawable` (حسب القرار في المرحلة 0)، خلف `RequiresWalletPin` — بينطبق على الخصم المباشر وعلى الـ `hold()` بنفس القاعدة.
2. ربط `WalletService::debit()` (سعر ثابت) و`hold()`/`capture()`/`release()` (سعر تقديري) بمسار الدفع، ودعم الدفع الجزئي (زي `useWallet()` في LeeTaxi).
3. الاسترجاع عند الإلغاء **بعد** التحصيل (`HandlesRefunds` بنمط dorr) — بيرجع لنفس `bucket` بتاع الدفع الأصلي. (الإلغاء **قبل** التحصيل بيستخدم `release()` مباشرة، مفيش استرجاع أصلاً لأن الفلوس ماتحركتش.)
4. اختبار: خدمة تقديرية — `hold` بـ100 → `capture` بـ80 → الـ20 الفرق يرجعوا متاحين فوراً من غير صف `wallet_transactions` إضافي.

**Deliverable:** خدمة/طلب حقيقي يتدفع من المحفظة، بسعر ثابت أو تقديري حسب طبيعتها.

---

## المرحلة 12 — اختياري (بعد استقرار كل ما سبق)

- عقوبات (Penalty) + عكسها (بنمط Jawad، مفيش soft delete — صف عكسي بنفس القاعدة).
- مكافآت/إحالة/نقاط.
- تحويل عملة تلقائي لو اتقرر لاحقاً.

---

## ترتيب سريع (ملخص للمراجعة)

| # | المرحلة | يعتمد على |
|---|---|---|
| 0 | قفل القرارات | — |
| 1 | الأساسات (`wallet_settings`) | 0 |
| 2 | نواة المحفظة (`WalletService`) 🔴 | 1 |
| 3 | تحديد الدولة (`CountryResolver`) | 0 |
| 4 | الحسابات المالية للنظام | 2 |
| 5 | طرق الدفع + بوابة واحدة | 3 |
| 6 | الشحن + الرسوم/الهدية | 2, 4, 5 |
| 6.1 | واجهة الموبايل (Android) — مرجعي، بالتوازي | 1, 6 |
| 7 | كشف الحساب + شاشات الأدمن | 2, 6 |
| 8 | السحب (Provider) | 2, 7 |
| 9 | أهلية الطلب (حد الدين) | 2 + موديول الطلبات |
| 10 | التحويل بين المستخدمين | 2 |
| 11 | الدفع من المحفظة | 2 + موديول الطلبات |
| 12 | اختياري | كل ما سبق |

---

## أفكار/تعديلات إضافية (هتتضاف هنا)
<!-- سيب المكان ده لأي تعديل على الترتيب بعد النقاش. -->

-
