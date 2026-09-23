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

1. إنشاء الموديول: `php artisan module:make Wallet` (nwidart) + تسجيله في `modules_statuses.json`.
2. `config/wallet.php`: morph map (`user`/`provider`/`admin`/`platform`) + تسجيله في `AppServiceProvider` (أو `WalletServiceProvider` بتاع الموديول) عبر `Relation::enforceMorphMap()`.
3. Migration: `wallet_settings` (جدول 3 في `wallet-structure.md`) + Model + Repository (على نمط `CountryRepository`).
4. Seeder: صف `wallet_settings` تلقائي لكل دولة موجودة حالياً + Observer على `Country::created` يعمل نفس الحاجة للدول الجديدة.
5. شاشة أدمن بسيطة (CRUD) لتعديل `wallet_settings` لكل دولة، على نمط `PlatformSettingController`.
6. Migration: `wallet_pins` (جدول 1.2) + Model `WalletPin`. `PinService`: `set()`/`verify()` (Argon2id + pepper من `config/wallet.php`)، عدّاد `failed_attempts` + `locked_until` بعد عدد محاولات معيّن، وMiddleware `RequiresWalletPin` جاهز للاستخدام (لسه من غير أي Route بتستخدمه — هيتفعّل في المراحل 6/8/10/11).
7. اختبار: إنشاء دولة جديدة → يتأكد إن `wallet_settings` اتعمل تلقائي بقيم افتراضية آمنة (`min_allowed_balance_*_minor = 0`).
8. اختبار: `PinService` — محاولات فاشلة متكررة → قفل مؤقت، ومحاولة صح بعد القفل بترفض لحد ما `locked_until` يعدي.

**Deliverable:** موديول Wallet موجود، وكل دولة ليها إعدادات محفظة، من غير أي محفظة أو حركة لسه.

---

## المرحلة 2 — نواة المحفظة (`WalletService`) 🔴 أهم مرحلة

**الهدف:** الجداول والخدمة اللي كل حاجة تانية هتُبنى عليها.

1. Migration: `wallets` (جدول 1) + Model `Wallet` + trait `HasWallets` يتضاف لـ `User` و`Provider`.
2. Migration: `wallet_transactions` (جدول 2) + Model `WalletTransaction` (immutable: بدون `update()`/`delete()` مفعّلين — يتقفلوا بـ guard في الموديل يرموا exception).
3. Migration: `wallet_holds` (جدول 2.1) + Model `WalletHold` (الاستثناء الوحيد اللي مسموح يتحدّث — لحد ما يتقفل).
4. `WalletService` (الباب الوحيد لتغيير أي رصيد):
   - `credit(wallet, amountMinor, bucket, type, ...)` / `debit(...)`.
   - `transfer(fromWallet, toWallet, amountMinor, ...)` — قفل المحفظتين **بترتيب الـ id تصاعدي دايماً** (منع deadlock — قاعدة عامة لأي عملية بتلمس أكتر من محفظة، مش خاصة بالتحويل بس)، والمستلم دايماً `spend_only`.
   - `reverse(transactionId, reason)` — بيعمل صف عكسي بنفس الـ `bucket`.
   - `manualAdjustment(wallet, amountMinor, bucket, reason, admin)` — للأدمن، الفلاج إجباري.
   - `hold(wallet, amountMinor, bucket, reference, reasonCode, expiresAt?)` — بيزوّد `held_{bucket}_minor` بس، **مفيش صف `wallet_transactions`**.
   - `capture(hold, capturedAmountMinor, type)` — صف `wallet_transactions` حقيقي بالمبلغ الفعلي، ويقفل الحجز كله (الفرق بيترجع متاح تلقائي).
   - `release(hold, reason)` — تحرير الحجز من غير أي حركة مالية.
   - كل دالة: `DB::transaction` + `lockForUpdate` + فحص `idempotency_key` **و`request_hash`** (نفس المفتاح + hash مختلف = رفض 409) + `CHECK` قبل الكتابة + تحديث `balance_after_minor`/`total_balance_after_minor`.
5. أمر `wallet:reconcile` — بيعيد حساب الأرصدة من `wallet_transactions` ويقارنها بـ `wallets`، وبيبعت تنبيه (log/notification) عند أي فرق. **وبنفس الأمر (أو أمر شقيق):** يحرر أي `wallet_holds` بحالة `active` عدّى `expires_at`.
6. Resource/API واحد (`WalletTransactionResource`) بيقرر اللون/الأيقونة/الترجمة لكل `type` — على نمط `WalletResource` في LeeTaxi بس من غير تكرار enums.
7. **اختبارات إلزامية (قبل أي مرحلة تانية):**
   - المثال المحسوب بالكامل من `wallet-plan.md § 10` (شحن 1000 → تحويل 500 → رجوع 500 → القابل للسحب = 500).
   - سباق متزامن: طلبين سحب/خصم في نفس اللحظة على نفس المحفظة (لازم واحد بس ينجح لو الرصيد مايكفيش الاتنين).
   - سباق التحويل: A→B وB→A في نفس اللحظة → مفيش deadlock (بترتيب الـ `id`)، والاتنين يتنفذوا بالترتيب من غير تجمّد.
   - نفس `idempotency_key` بنفس `request_hash` مرتين → نفس النتيجة، عملية واحدة بس اتنفذت. نفس `idempotency_key` بـ `request_hash` مختلف → 409.
   - `hold()` بيقلل المتاح (`bucket_minor − held_bucket_minor`) من غير ما يعمل أي صف `wallet_transactions`.
   - `capture()` جزئي (مبلغ أقل من الحجز) → صف واحد بالمبلغ الفعلي، والفرق يترجع متاح فوراً، والحجز يقفل.
   - `release()` → المتاح يرجع زي ما كان بالظبط، صفر صفوف `wallet_transactions` جديدة.
   - حجز `active` عدّى `expires_at` → أمر التحرير التلقائي بيقفله.
   - `wallet:reconcile` بيرجع "مطابق" بعد أي سلسلة عمليات عشوائية.
   - محاولة `update()`/`delete()` مباشر على `WalletTransaction` → لازم يفشل.

**Deliverable:** تقدر تنشئ محفظة، تزوّد/تخصم رصيدها بفلاج صح، تحوّل بين محفظتين، وتعكس عملية — كله من غير أي واجهة API لسه، بس بـ Unit/Feature tests.

---

## المرحلة 3 — تحديد الدولة (`CountryResolver`)

1. `CountryResolver` service (`app/Services/General` أو `Modules/Wallet/app/Services`): الترتيب المتفق عليه (اختيار صريح → دولة البروفايل → IP hint مع cache → `is_default`).
2. `config/geo.php`: مزوّد الـ IP قابل للتبديل (هيدر CDN / GeoIP محلي / API خارجي)، مع `cache` و`timeout` قصير.
3. Middleware اختياري (`ResolveCountryContext`) بيحط الدولة المحسوبة في الـ request عشان الـ Controllers تستخدمها.
4. اختبار: قطع الاتصال بمزوّد الـ IP بالكامل → النظام يكمل عادي على `is_default` من غير خطأ 500.

**Deliverable:** أي Request عنده دولة سياق واضحة، حتى لو من غير مستخدم مسجّل دخول.

---

## المرحلة 4 — الحسابات المالية للنظام (`FinancialLedgerService`)

**ليه قبل الشحن والرسوم؟** عشان لما نبني الشحن في المرحلة 6، كل حركة رسوم/هدية تتسجل هنا من أول يوم، مش نرجع نضيفها لاحقاً.

1. Migrations: `financial_categories`, `financial_category_translations`, `financial_entries` (جدول 5).
2. Seeder: الفئات الأساسية الأربعة (`topup_fee`, `promo_bonus_cost`, `withdrawal_processing`, `manual_adjustment`) بـ `is_system=true`.
3. `FinancialLedgerService::record(categorySlug, type, amount, currency, country, reference, notes, walletTransaction?)` — بيرمي exception واضح لو الـ slug مش موجود (مفيش fallback صامت).
4. Guard في موديل `FinancialCategory` يمنع حذف/تعديل `slug` لو `is_system=true`.
5. شاشة أدمن: قائمة + فلاتر (تاريخ/فئة/نوع) + ملخص (إجمالي دخل/مصروف/صافي)، على نمط `FinanceController` في Jawad بس بدون مشاكله (بند "مشاكل نتجنبها" في `wallet-plan.md § 13`).

**Deliverable:** دفتر إيرادات/مصروفات شغال ومربوط، جاهز يستقبل أول صف من المرحلة اللي بعدها.

---

## المرحلة 5 — طرق الدفع + بوابة واحدة

1. Migrations: `payment_methods`, `payment_method_translations`, `payment_method_country` (جدول 8).
2. `PaymentGateway` interface (`initiate` / `handleCallback` / `verify` / `refund`) + `PaymentGatewayRegistry` (بيختار الـ driver من `payment_methods.gateway`).
3. **بوابة واحدة كبداية** (يتحدد في المرحلة 0 — على الأرجح MyFatoorah أو حسب أول دولة هتتفعّل): نقل منطق التكامل من LeeTaxi/Jawad لكلاس `Gateways/{Name}Gateway` مستقل.
4. CRUD أدمن لطرق الدفع (إضافة/تعديل/ربط بدول/تفعيل عام)، `credentials` بـ `encrypted:array` ومبتتعرضش في أي Resource.
5. Endpoint عام: "طرق الدفع المتاحة لدولة X" (عامة + مربوطة بالدولة، فعّالة بس) — بيُستخدم في المرحلة الجاية.
6. اختبار: طريقة دفع مربوطة بدولة تانية **ما تظهرش** لمستخدم دولة مختلفة، وطريقة `is_global` **تظهر للكل**.

**Deliverable:** أدمن يقدر يضيف طريقة دفع ويحدد لأي دول، ومستخدم أي دولة يشوف الطرق الصح بس.

---

## المرحلة 6 — الشحن الأونلاين + `quote` + الرسوم/الهدية

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

## المرحلة 6.1 — واجهة المحفظة في تطبيق الموبايل (Android)

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

## المرحلة 7 — كشف الحساب وشاشات الأدمن

1. Endpoint كشف حساب (User/Provider): قائمة `wallet_transactions` بفلاتر تاريخ + عرض `bucket` بوضوح (إجمالي / قابل للسحب / Hold) لكل صف.
2. Endpoint رصيد المحفظة الحالي (حسب دولة السياق) — يوضح لو فيه محافظ تانية بدول تانية (Read-only).
3. شاشة أدمن: كل المحافظ + بحث بالمالك/الدولة + تفاصيل حركات أي محفظة + زرار "تسوية يدوية" (Bucket إجباري + سبب).
4. صلاحيات (`spatie/laravel-permission`): `wallet transaction read`, `wallet manual adjustment`, `wallet fee rule manage`, `financial ledger read`, `online transaction read`, `online transaction reconcile`... (حسب قرار المرحلة 0).

**Deliverable:** شفافية كاملة للمستخدم والأدمن على كل قرش في المحفظة.

---

## المرحلة 8 — السحب (Provider)

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

## المرحلة 9 — الأهلية لطلب/استقبال الخدمات (`WalletEligibilityService`)

**تتنفذ لما موديول الطلبات/الحجوزات يبدأ فعلياً** (حالياً مفيش طلبات في dorr، حسب `wallet-plan.md § 6`).

1. `WalletEligibilityService::canRequestService(owner, country, category)` — بيقرأ `wallet_settings.min_allowed_balance_*` ويقارنه بإجمالي رصيد المحفظة.
2. دمجها في مسار قبول الطلب (Provider) وإنشاء الطلب (User) — فحص وقت الإرسال **ووقت التأكيد النهائي** (سباق).
3. رسالة واضحة للمستخدم/المزوّد لما يكون موقوف: "رصيدك X، محتاج تشحن Y عشان تكمل".

**Deliverable:** حد الدين شغال فعلياً على أول موديول خدمة يتبني.

---

## المرحلة 10 — التحويل بين المستخدمين (لو اتقرر تفعيله)

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
