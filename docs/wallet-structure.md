# Wallet — بنية قاعدة البيانات (DRAFT)

> **الحالة: غير معتمدة.** ده تصميم تفصيلي مبني على [wallet-plan.md](wallet-plan.md). فيه أماكن اتحطت فيها قيمة افتراضية معقولة (متعلّم عليها **افتراض**) عشان الجدول يبقى قابل للمراجعة الكاملة — **مش قرار نهائي**. راجع قسم "الافتراضات المفتوحة" في آخر الملف قبل أي migration فعلي.
> **ممنوع تنفيذ أي كود أو migration من الملف ده** قبل الاعتماد (حسب [AGENTS.md](../AGENTS.md)).

آخر تحديث: 2026-09-23 — إضافة `wallet_pins` (مسار الموبايل)، و`wallet_holds` (Hold/Capture مُوحّد مع طلبات السحب).

---

## 0. قواعد عامة قبل الجداول

### أماكن الكود (قرار محسوم)
**موديول جديد `Modules/Wallet`** (مش `app/General`)، لأن المحفظة منطق بزنس ثقيل (مش catalog بسيط زي Country/Currency) وبتتستخدم من 3 حراس (`admin_api`, `user_api`, `provider_api`) بنفس نمط `Modules/Admin`, `Modules/User`, `Modules/Provider` الموجودين. البنية:
```
Modules/Wallet/
  app/Models/            Wallet, WalletTransaction, WalletSetting, WalletFeeRule,
                          FinancialCategory, FinancialEntry, WithdrawalMethod,
                          WithdrawalRequest, PaymentMethod, PaymentTransaction
  app/Services/           WalletService, FeeService, WalletEligibilityService,
                          FinancialLedgerService, WithdrawalService,
                          PaymentCompletionService, PaymentGatewayRegistry
  app/Services/Gateways/  MyFatoorahGateway, UrPayGateway, ArbGateway (+ PaymentGateway interface)
  app/Repositories/       (نفس نمط BaseRepository/TranslatableRepository الموجود في app/Repositories)
  app/Http/Controllers/   (admin/v1/wallets/*, user/v1/wallet/*, provider/v1/wallet/*)
  app/Http/Requests/
  app/Http/Resources/
  app/Console/Commands/   WalletReconcileCommand
  database/migrations/    (كل الجداول تحت)
  database/seeders/       FinancialCategorySeeder, PaymentMethodSeeder (فارغ/تجريبي)
  routes/api.php, admin.php, user.php, provider.php
  config/wallet.php        morph map + إعدادات ثابتة (اسم بوابات، حدود صارمة)
```
`User` (`Modules/User/app/Models/User.php`) و`Provider` (`Modules/Provider/app/Models/Provider.php`) ياخدوا trait `HasWallets` (من موديول Wallet) بعلاقة `wallets()` فقط — **من غير أي منطق مالي جوه الموديلين**.

### الأنواع والتسميات المستخدمة في كل الجداول
- **المبالغ:** كل عمود مالي **`bigint` بوحدة "الفلس/الهللة" (minor units)** — مش `decimal` ومش `float`. الاسم دايماً بلاحقة **`_minor`** (`amount_minor`, `withdrawable_minor`...) عشان محدش يستخدمها غلط على إنها بالريال كامل. العرض للمستخدم بيتحول حسب `currencies.decimal_places` وقت الـ Resource بس، **مش التخزين**. **(قرار محسوم بعد مراجعة `dorr-wallet-schema.pdf` — كان سؤال #12 المفتوح في wallet-plan.md.)**
- **قفل أكتر من محفظة في نفس العملية (تحويل...إلخ):** لازم يتم **بترتيب `id` تصاعدي دايماً**، بغض النظر مين طرف العملية، لمنع الـ Deadlock — قاعدة عامة في كل `WalletService`.
- **`idempotency_key` + `request_hash`:** أي جدول فيه `idempotency_key` (`wallet_transactions`, `payment_transactions`) لازم معاه `request_hash` (SHA-256 لمحتوى الطلب). نفس المفتاح + نفس الـ hash = ترجيع نفس النتيجة القديمة. نفس المفتاح + hash مختلف = **رفض بـ 409 Conflict** (مش تنفيذ صامت ولا تجاهل).
- **الحالة العامة (تفعيل/تعطيل):** عمود `status` من نوع `boolean`، بقيمة افتراضية `Status::Active->value` (enum `App\Enums\Status`، نفس نمط باقي dorr).
- **حالات دومين خاصة** (نوع حركة، حالة طلب سحب...): PHP enum **backed بـ `string`** (نفس نمط `UserStatus`, `Gender` في dorr)، مش أرقام.
- **التعليقات:** كل عمود بتعليق عربي (`->comment('...')`) زي باقي migrations المشروع.
- **الحذف:** `softDeletes()` على الجداول الإعدادية/المرجعية (`payment_methods`, `withdrawal_methods`, `wallet_fee_rules`, `financial_categories`, `financial_entries`). **الجداول المالية الأساسية (`wallets`, `wallet_transactions`, `payment_transactions`, `withdrawal_requests`) من غير `softDeletes`** — مفيش حذف خالص، التصحيح بيبقى حركة/حالة جديدة (خط أحمر، فصل 10 في الخطة).
- **الترجمة:** نفس نمط `country_translations`/`service_category_translations` الموجود بالفعل: جدول `..._translations` بعمود `locale` مفتوح (مش عمود ثابت لكل لغة) + `unique(parent_id, locale)`.
- **المورف:** `config/wallet.php` بيسجل morph map ثابت (`Relation::enforceMorphMap`)، مش أسماء كلاسات كاملة في الـ DB:
  ```php
  'user' => Modules\User\Models\User::class,
  'provider' => Modules\Provider\Models\Provider::class,
  'admin' => Modules\Admin\Models\Admin::class,
  'platform' => null, // مفيش موديول حقيقي؛ owner_id ثابت = 0 (بند 1.1)
  ```

---

## 1. `wallets`

محفظة واحدة لكل (مالك + دولة). أرصدة مخزّنة (Hybrid، فصل 11 في الخطة) بتتحدّث بس من `WalletService`.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `owner_type` | `string` | NOT NULL | `user` / `provider` / `platform` (morph map) |
| `owner_id` | `unsignedBigInteger` | NOT NULL | معرف المالك. **`platform` → ثابت `0`** (بند 1.1) |
| `country_id` | `foreignId → countries` | NOT NULL, `restrictOnDelete` | دولة المحفظة |
| `currency_id` | `foreignId → currencies` | NOT NULL, `restrictOnDelete` | عملة المحفظة، **لقطة وقت الإنشاء من `countries.currency_id`، لا تتغيّر بعد كده** |
| `withdrawable_minor` | `bigint` | default `0` | الرصيد القابل للسحب بالفلس/الهللة (ممكن يبقى سالب لو مسموح دين، فصل 14) |
| `spend_only_minor` | `bigint` | default `0`, CHECK `>= 0` | رصيد Hold — استخدام داخل التطبيق بس |
| `held_withdrawable_minor` | `bigint` | default `0`, CHECK `>= 0` | محجوز من `withdrawable` بحجوزات نشطة (`wallet_holds`، جدول 2.1) — طلبات سحب أو خدمات بسعر تقديري |
| `held_spend_only_minor` | `bigint` | default `0`, CHECK `>= 0` | نفس الفكرة، محجوز من `spend_only` |
| `status` | `boolean` | default `Status::Active` | تجميد المحفظة (أدمن) |
| `last_reconciled_at` | `timestamp` | nullable | آخر مرة أمر `wallet:reconcile` أكّد إن الرصيد مطابق للحركات |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس/قيود:**
- `unique(owner_type, owner_id, country_id)`
- **`unique(id, country_id, currency_id)`** — مفتاح مركب (composite) بيُستخدم كـ FK من الجداول التابعة (`wallet_transactions`, `wallet_pins`...) عشان الـ **DB نفسه** يمنع أي صف تابع يحمل دولة/عملة مختلفة عن محفظته (فكرة من `dorr-wallet-schema.pdf`، أقوى من مجرد "نسخ" القيمة).
- `index(country_id)`, `index(currency_id)`, `index(owner_type, owner_id)`

**بند 1.1 — محفظة النظام (`platform`):** مفيش موديل Eloquent حقيقي لـ "النظام"، فبدل جدول إضافي، `owner_type = 'platform'` و`owner_id` بيتثبّت على `0`. محفظة واحدة لكل دولة (`unique(owner_type, owner_id, country_id)` بيسمح بيها لأن `owner_id=0` ثابت مش NULL). بتتعمل seed تلقائي عند تفعيل أي دولة. **افتراض — بديل لسؤال #4 (حسابات النظام) في wallet-plan.md.**

### 1.2 `wallet_pins` — رقم سري للعمليات المالية

طبقة حماية إضافية **منفصلة عن تسجيل الدخول**، بتتطلب وقت: **الشحن، التحويل، دفع أي خدمة من المحفظة، والسحب** (وتعديل/إضافة وسيلة سحب — بند مفتوح، راجع الأسئلة تحت). PIN لكل مالك، مش لكل محفظة (نفس الرقم لكل دول المستخدم).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `owner_type` | `string` | NOT NULL, morph | |
| `owner_id` | `unsignedBigInteger` | NOT NULL | |
| `pin_hash` | `string` | NOT NULL | **Argon2id + server pepper** — غير قابل لفك التشفير |
| `failed_attempts` | `unsignedTinyInteger` | default `0` | |
| `locked_until` | `timestamp` | nullable | قفل مؤقت بعد محاولات فاشلة متتالية |
| `changed_at` | `timestamp` | nullable | آخر تغيير (لتفعيل فترة تهدئة قبل أول سحب بعد تغيير الـ PIN) |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس:** `unique(owner_type, owner_id)`.

**ملاحظة أمان:** PIN من 4 أرقام = 10,000 احتمال بس، فالأمان الحقيقي في **حد المحاولات + القفل المؤقت** (`failed_attempts`/`locked_until`)، مش في قوة التشفير. **`pin_reset` (استرجاع/تغيير PIN منسي) هيتصمم لاحقاً كجزء من المرحلة، مش في النواة الأولى** — عشان منعقدش دلوقتي، بس الجدول أعلاه كافي لإنشاء PIN وتغييره وهو الشخص لسه عارف القديم.

**قرارات محسومة (بعد مراجعة `androidApp`):**
- PIN إجباري على: **الشحن، التحويل، دفع أي خدمة، السحب، وتعديل/إضافة وسيلة سحب.**
- **إنشاء أول PIN بطريقة Lazy** — مش إجباري وقت التسجيل. بيتطلب أول مرة المستخدم يدخل شاشة إعدادات الـ PIN أو يحاول عملية محتاجة PIN ومفيهوش واحد، فيظهر إدخال مزدوج (PIN + تأكيد PIN) على الموبايل.

---

## 2. `wallet_transactions`

**دفتر ثابت (immutable) — العمود الوحيد اللي مسموح يتحدّث فيه أي صف قديم هو *مفيش*.** التصحيح = صف جديد بـ `reverses_transaction_id`.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | NOT NULL, unique | معرف خارجي آمن (مش تسلسلي) للـ API |
| `wallet_id` | `foreignId → wallets` | NOT NULL, `restrictOnDelete` | |
| `operation_id` | `uuid` | NOT NULL | بيجمع كل الصفوف الناتجة عن نفس الحدث (شحن+رسوم، تحويل مرسل+مستقبل، استرجاع+إلغاء هدية) |
| `direction` | `string` (enum `credit`/`debit`) | NOT NULL | |
| `bucket` | `string` (enum `withdrawable`/`spend_only`) | **NOT NULL, من غير default** | الفلاج (فصل 10 🔴). المبرمج لازم يحدده صراحة |
| `type` | `string` (enum) | NOT NULL | راجع قائمة الأنواع تحت |
| `amount_minor` | `bigint` | NOT NULL, CHECK `> 0` | موجب دايماً؛ الاتجاه من `direction` |
| `currency_id` | `foreignId → currencies` | NOT NULL, `restrictOnDelete` | لقطة من المحفظة |
| `country_id` | `foreignId → countries` | NOT NULL, `restrictOnDelete` | لقطة من المحفظة — **composite FK**: `(wallet_id, country_id, currency_id) → wallets(id, country_id, currency_id)` (بند 1، فهارس) بيمنع تضارب الدولة/العملة على مستوى الـ DB |
| `balance_after_minor` | `bigint` | NOT NULL | رصيد الـ `bucket` ده بعد الصف مباشرة |
| `total_balance_after_minor` | `bigint` | NOT NULL | إجمالي المحفظة (`withdrawable + spend_only`) بعد الصف |
| `reference_type` / `reference_id` | `string` / `unsignedBigInteger` | nullable, morph | مرجع خارجي (حجز/طلب/فاتورة — لاحقاً) |
| `counterparty_wallet_id` | `foreignId → wallets` | nullable, `restrictOnDelete` | محفظة الطرف التاني في التحويل |
| `reverses_transaction_id` | `foreignId → wallet_transactions` | nullable, self, `restrictOnDelete` | لو الصف ده عكس صف قديم |
| `fee_rule_id` | `foreignId → wallet_fee_rules` | nullable, `nullOnDelete` | لو الصف رسوم/هدية شحن |
| `fee_percent` | `decimal(8,4)` | nullable | النسبة المطبّقة فعلياً (لقطة، فصل 12) |
| `payment_transaction_id` | `foreignId → payment_transactions` | nullable, `nullOnDelete` | لو الصف ناتج عن شحن أونلاين |
| `idempotency_key` | `string` | nullable, **unique** | مفتاح منع التكرار (على الصف "الرئيسي" في العملية بس) |
| `request_hash` | `char(64)` | nullable | SHA-256 لمحتوى الطلب. نفس `idempotency_key` بـ hash مختلف = رفض 409 (قسم 0) |
| `notes` | `json` | nullable | `{"key": "wallet.notes.topup_gift", "variables": {...}}` — مفتاح ترجمة + متغيرات |
| `created_by_type` / `created_by_id` | `string` / `unsignedBigInteger` | nullable, morph | `system` / `admin` / نفس صاحب المحفظة |
| `created_at` | `timestamp` | NOT NULL, `useCurrent()` | **من غير `updated_at`** — تأكيد إضافي على الثبات على مستوى الـ schema |

**أنواع `type` (enum `WalletTransactionType`):**
`topup`, `topup_fee`, `topup_bonus`, `transfer_out`, `transfer_in`, `withdrawal`, `refund`, `penalty`, `service_payment`, `service_earning`, `manual_adjustment`, `reversal`. (لاحقاً: `referral_reward`, `discount_compensation`, إلخ — حسب قرارات فصل 10 بند "مكافآت").

**فهارس:**
- `index(wallet_id, created_at)`
- `index(operation_id)`
- `unique(idempotency_key)` (nullable-safe)
- `index(reference_type, reference_id)`
- `index(type)`, `index(bucket)`
- `index(payment_transaction_id)`

**CHECK constraints:** `amount_minor > 0`، `bucket IN ('withdrawable','spend_only')`، `direction IN ('credit','debit')`.

**كيف نعرف إن صف اتعكس؟** بالبحث عن صف تاني بـ `reverses_transaction_id = هذا الـ id` — من غير أي تعديل على الصف الأصلي.

### 2.1 `wallet_holds` — حجز مبلغ من غير ما يتحرك (Hold/Capture)

**الفكرة:** بدل ما نخصم فوراً بسعر تقديري ونسترجع الفرق بعدين (زي LeeTaxi)، بنحجز المبلغ التقديري **من غير أي حركة مالية حقيقية**، وبعدين إما **نحصّل** المبلغ الفعلي (يتعمل صف `wallet_transactions` حقيقي بيه بس، والباقي يترجع تلقائي) أو **نحرر** الحجز كله لو العملية اتلغت (من غير أي حركة خالص). ده **الآلية العامة الموحّدة** لأي حجز في المحفظة — **بما فيها طلبات السحب** (بدل الأسلوب اليدوي القديم بعمود `reserved_withdrawable_minor` المباشر)، عشان منعملش مسارين مختلفين لنفس الفكرة.

**مبني لأي نوع خدمة:** سعر ثابت = `WalletService::debit()` مباشر زي ما هو (مفيش داعي لحجز وسيط). سعر تقديري = `hold()` وقت الطلب، `capture()` بالمبلغ الفعلي بعد التنفيذ.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | NOT NULL, unique | |
| `wallet_id` | `foreignId → wallets` | NOT NULL, `restrictOnDelete` | |
| `bucket` | `string` (enum `withdrawable`/`spend_only`) | **NOT NULL, من غير default** | نفس فلسفة الفلاج في `wallet_transactions` |
| `amount_minor` | `bigint` | NOT NULL, CHECK `> 0` | المبلغ المحجوز الأصلي (التقديري) |
| `status` | `string` (enum `active`/`captured`/`released`/`expired`) | NOT NULL, default `active` | **الجدول ده الوحيد المسموح يتحدّث فيه صف قائم** — لحد ما الحالة توصل لحالة نهائية، وبعدها يتقفل زيه زي أي صف تاني |
| `captured_amount_minor` | `bigint` | nullable, CHECK `<= amount_minor` | بيتملى وقت `capture()` بس. الفرق (`amount_minor - captured_amount_minor`) بيترجع متاح تلقائي |
| `reference_type` / `reference_id` | `string` / `unsignedBigInteger` | nullable, morph | الحجز/الطلب اللي الحجز ده ليه (`withdrawal_request`, `booking`...) |
| `reason_code` | `string` | nullable | `withdrawal_request`, `service_estimate`... |
| `expires_at` | `timestamp` | nullable | صمام أمان (Orphan Hold): لو موجود، أمر مجدول بيحرر الحجز تلقائي بعد الوقت ده لو لسه `active`. **`null`** مسموح بس للحجوزات اللي إجراء بزنس تاني (زي موافقة/رفض أدمن) هو اللي بيقفلها بثقة (زي طلبات السحب) |
| `idempotency_key` | `string` | nullable, unique | منع تكرار إنشاء الحجز |
| `wallet_transaction_id` | `foreignId → wallet_transactions` | nullable, `nullOnDelete` | الصف الحقيقي الناتج عند `capture()` |
| `created_at` / `updated_at` | `timestamp` | | **استثناء وحيد من قاعدة "مفيش `updated_at`"** — الحجز حالة انتقالية بطبيعتها لحد ما يتقفل |

**فهارس:** `index(wallet_id, status)`, `index(reference_type, reference_id)`, `unique(idempotency_key)`, `index(status, expires_at)` (لأمر التحرير التلقائي).

**العمليات في `WalletService`:**
- `hold(wallet, amountMinor, bucket, reference, reasonCode, expiresAt?)` — `lockForUpdate` على المحفظة، تحقق إن `bucket_minor − held_bucket_minor ≥ amountMinor`، إنشاء صف `active`، `held_{bucket}_minor += amountMinor`. **مفيش صف `wallet_transactions`.**
- `capture(hold, capturedAmountMinor, type)` — تحقق `capturedAmountMinor ≤ hold.amount_minor`، إنشاء صف `wallet_transactions` حقيقي (`debit`, نفس الـ `bucket`) بمبلغ `capturedAmountMinor` بس، `bucket_minor -= capturedAmountMinor`، `held_{bucket}_minor -= hold.amount_minor` (تحرير الحجز **كله**، والفرق بيرجع متاح تلقائي بمجرد إن `held` قل)، الحجز يبقى `captured`.
- `release(hold, reason)` — `held_{bucket}_minor -= hold.amount_minor`، الحجز يبقى `released`. **مفيش صف `wallet_transactions`** (مفيش حاجة حصلت مالياً).
- أمر مجدول (يتضم لـ `wallet:reconcile`) بيحرر أي حجز `active` عدّى `expires_at`، وشاشة أدمن بسيطة تعرض الحجوزات المفتوحة من فترة طويلة (نفس فكرة "Online Transactions" بس للحجوزات).

**التوفر (Available balance) بعد إضافة الحجوزات:** `withdrawable_minor − held_withdrawable_minor` هو القابل للسحب أو الصرف فعلياً، مش `withdrawable_minor` وحده.

---

## 3. `wallet_settings`

إعداد واحد **لكل دولة** (مش سطر عام واحد زي `::first()` في المشروعين المرجعيين).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `country_id` | `foreignId → countries` | NOT NULL, **unique**, `cascadeOnDelete` | |
| `min_topup_minor` | `bigint` | nullable | أقل مبلغ شحن |
| `max_topup_minor` | `bigint` | nullable | أعلى مبلغ شحن |
| `min_withdrawal_minor` | `bigint` | nullable | أقل مبلغ سحب |
| `max_withdrawal_minor` | `bigint` | nullable | أعلى مبلغ سحب لكل طلب |
| `transfer_max_per_transaction_minor` | `bigint` | nullable | أقصى تحويل لكل عملية |
| `transfer_max_per_day_minor` | `bigint` | nullable | أقصى تحويل يومي |
| `transfer_max_per_month_minor` | `bigint` | nullable | أقصى تحويل شهري |
| `transfers_enabled` | `boolean` | default `false` | تفعيل التحويل بين المستخدمين في الدولة دي (سؤال مفتوح، فصل 16 بند التحويل) |
| `min_allowed_balance_provider_minor` | `bigint` | NOT NULL, default `0` | حد الدين **signed** لمقدم الخدمة (فصل 14). مثلاً `-10000` = دين لحد 100 ريال |
| `min_allowed_balance_user_minor` | `bigint` | NOT NULL, default `0` | حد الدين **signed** للمستخدم (نتيجة عقوبات) |
| `status` | `boolean` | default `Status::Active` | |
| `created_at` / `updated_at` | `timestamp` | | |

**بند 3.1:** الصف بيتعمل تلقائي (seeder/observer) عند إضافة دولة جديدة بقيم افتراضية محافظة (`min_allowed_balance_* = 0` يعني مفيش دين مسموح افتراضياً). **لو الصف ناقص، أي فحص أهلية يفشل بأمان (fail closed)** — مفيش قيمة افتراضية صامتة في الكود (فصل 14).

---

## 4. `wallet_fee_rules`

قواعد الرسوم/الهدية على الشحن (فصل 12). **Default الفعلي وقت التشغيل = مفيش قاعدة مطابقة → `percent = 0`.**

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `operation` | `string` (enum) | NOT NULL, default `topup` | `topup` بس دلوقتي |
| `country_id` | `foreignId → countries` | nullable, `cascadeOnDelete` | `null` = كل الدول |
| `payment_method_id` | `foreignId → payment_methods` | nullable, `cascadeOnDelete` | `null` = كل الطرق |
| `owner_type` | `string` | nullable | `user` / `provider` / `null` = الاتنين |
| `percent` | `decimal(8,4)` | NOT NULL, default `0` | **signed**: موجب = رسوم، سالب = هدية |
| `min_amount_minor` | `bigint` | nullable | أقل قيمة رسوم/هدية بالمطلق لكل عملية |
| `max_amount_minor` | `bigint` | nullable | أقصى قيمة رسوم/هدية بالمطلق لكل عملية (cap) |
| `starts_at` | `timestamp` | nullable | `null` = بدأ فعلاً |
| `ends_at` | `timestamp` | nullable | `null` = مفتوح |
| `max_uses_per_owner` | `unsignedInteger` | nullable | يتحقق بعدّ صفوف `wallet_transactions` بنفس `fee_rule_id` لنفس المحفظة — **مفيش جدول عدّاد منفصل** |
| `budget_total_minor` | `bigint` | nullable | أقصى تكلفة إجمالية للعرض (هدايا) |
| `budget_used_minor` | `bigint` | NOT NULL, default `0` | بيتحدّث ذرّياً (`increment` جوه نفس الـ transaction) كل مرة تتطبّق القاعدة |
| `priority` | `unsignedInteger` | default `0` | أعلى رقم يكسب عند التعادل |
| `status` | `boolean` | default `Status::Active` | |
| `created_by` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `updated_by` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**`wallet_fee_rule_translations`** (اسم/وصف العرض للأدمن وواجهة المستخدم): `id`, `wallet_fee_rule_id` (FK cascade), `locale`, `name`, `description` (nullable), timestamps، `unique(wallet_fee_rule_id, locale)`.

**فهارس:** `index(operation, country_id, payment_method_id, status)`, `index(starts_at, ends_at)`.

**اختيار القاعدة عند الشحن:** الأكثر تحديداً بيكسب (دولة+طريقة دفع > دولة فقط > طريقة دفع فقط > عامة)، وعند التعادل `priority` الأعلى، وعند التعادل التام أقدم `id`. **مفيش تجميع بين قاعدتين.**

---

## 5. `financial_categories` + `financial_entries`

دفتر إيرادات/مصروفات **النظام** (منفصل عن حركات محافظ الأفراد)، فصل 13.

### `financial_categories`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `slug` | `string` | NOT NULL, **unique** | مفتاح ثابت يُستخدم في الكود (`topup_fee`, `promo_bonus_cost`, `withdrawal_fee`, `referral_reward`...) |
| `type` | `string` (enum `income`/`expense`) | NOT NULL | |
| `is_system` | `boolean` | default `false` | فئات الـ seeder — مينفعش تتمسح |
| `status` | `boolean` | default `Status::Active` | |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes (بس مينفعش لو `is_system`، يتفرض بـ guard في الموديل) | |

**`financial_category_translations`:** `id`, `financial_category_id` (FK cascade), `locale`, `name`, timestamps، `unique(financial_category_id, locale)`.

### `financial_entries`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `category_id` | `foreignId → financial_categories` | NOT NULL, `restrictOnDelete` | |
| `type` | `string` (enum `income`/`expense`) | NOT NULL | لازم يطابق `category.type` (يتفرض بالـ Service) |
| `amount_minor` | `bigint` | NOT NULL, CHECK `> 0` | |
| `currency_id` | `foreignId → currencies` | NOT NULL, `restrictOnDelete` | |
| `country_id` | `foreignId → countries` | nullable, `nullOnDelete` | `null` = صف عام (مش خاص بدولة) |
| `entry_date` | `date` | NOT NULL | |
| `description` | `string` | nullable | |
| `notes` | `json` | nullable | مفتاح ترجمة + متغيرات |
| `reference_type` / `reference_id` | `string` / `unsignedBigInteger` | nullable, **morph حقيقي** (مش نص حر زي Jawad) | |
| `wallet_transaction_id` | `foreignId → wallet_transactions` | nullable, `nullOnDelete` | لو الصف ناتج عن حركة محفظة (رسوم/هدية شحن) |
| `created_by_type` / `created_by_id` | `string` / `unsignedBigInteger` | nullable, morph (`admin`/`system`) | |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**فهارس:** `index(category_id, entry_date)`, `index(country_id)`, `index(reference_type, reference_id)`, `index(wallet_transaction_id)`.

**فئات النظام الأساسية (seeder، `is_system=true`):** `topup_fee` (income), `promo_bonus_cost` (expense), `withdrawal_processing` (income/expense حسب لو فيه رسوم سحب لاحقاً), `manual_adjustment` (income/expense — تسويات الأدمن). **باقي الفئات (عمولة رحلة، إحالة، مكافآت...) هتتضاف مع موديول الطلبات لاحقاً، مش جزء من النواة دي.**

---

## 6. `withdrawal_methods`

وسائل السحب المحفوظة لصاحب المحفظة (Provider بالأساس، مع إمكانية توسيعها لـ User لاحقاً لو اتقرر).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `owner_type` | `string` | NOT NULL, morph | `provider` (حالياً) |
| `owner_id` | `unsignedBigInteger` | NOT NULL | |
| `type` | `string` (enum `bank`/`mobile_wallet`) | NOT NULL | |
| `label` | `string` | nullable | اسم مستعار يختاره المستخدم |
| `data` | `json`, cast `encrypted:array` | NOT NULL | IBAN/اسم البنك أو رقم محفظة الموبايل |
| `is_favorite` | `boolean` | default `false` | |
| `status` | `boolean` | default `Status::Active` | |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**فهارس:** `index(owner_type, owner_id)`.

---

## 7. `withdrawal_requests`

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `wallet_id` | `foreignId → wallets` | NOT NULL, `restrictOnDelete` | |
| `withdrawal_method_id` | `foreignId → withdrawal_methods` | NOT NULL, `restrictOnDelete` | |
| `amount_minor` | `bigint` | NOT NULL, CHECK `> 0` | |
| `status` | `string` (enum `pending`/`approved`/`rejected`) | NOT NULL, default `pending` | |
| `note` | `text` | nullable | ملاحظة الأدمن عند القبول |
| `rejection_reason` | `text` | nullable, **NOT NULL لو الحالة `rejected`** (يتفرض بالـ Request) | |
| `reviewed_by` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `reviewed_at` | `timestamp` | nullable | |
| `hold_id` | `foreignId → wallet_holds` | NOT NULL, `restrictOnDelete` | الحجز اللي اتعمل وقت إنشاء الطلب (جدول 2.1) — منه بنوصل لـ `wallet_transaction_id` الناتج عند القبول |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**الإيصال:** عبر Spatie Media Library (collection `receipt`, إجباري عند `approve`) — مش عمود منفصل، زي باقي ملفات المشروع (`HasMediaTrait`).

**فهارس:** `index(wallet_id, status)`, `index(status)`.

**التدفق (عبر `wallet_holds`، بدون منطق حجز منفصل):**
1. إنشاء الطلب → `WalletService::hold(wallet, amountMinor, bucket: withdrawable, reference: withdrawal_request, expiresAt: null)` (ضمن `min/max_withdrawal_minor` و`min_allowed_balance_*_minor` كفحص إضافي قبل الحجز) → صف `wallet_holds` بحالة `active` (بـ `expires_at = null` عمداً — الطلب بيتقفل بقرار أدمن مش بتايمر) → إنشاء `withdrawal_requests` بـ `hold_id` و`status=pending`.
2. قبول → `WalletService::capture(hold, amountMinor, type: withdrawal)` — بينشئ صف `wallet_transactions` الحقيقي ويقفل الحجز → الطلب `approved`.
3. رفض → `WalletService::release(hold, reason)` — تحرير الحجز من غير أي حركة مالية → الطلب `rejected` + `rejection_reason` إجباري.

---

## 8. `payment_methods` + الترجمة + الربط بالدول

### `payment_methods`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `code` | `string` | NOT NULL, unique | `myfatoorah_card`, `urpay_wallet`, `arb_card`, `manual_bank_transfer`... |
| `gateway` | `string` (enum) | NOT NULL | مفتاح الـ driver: `myfatoorah` / `urpay` / `arb` / `manual` |
| `type` | `string` (enum `online`/`manual`) | NOT NULL | |
| `is_global` | `boolean` | default `false` | تظهر لكل الدول من غير الحاجة لصف في `payment_method_country` |
| `supports_topup` | `boolean` | default `true` | (احتياطي لعمليات تانية لاحقاً) |
| `status` | `boolean` | default `Status::Active` | |
| `sort_order` | `unsignedInteger` | default `0` | |
| `credentials` | `json`, cast `encrypted:array` | nullable | `null` لو `type=manual`. **ما بتتعرضش في أي API Resource أبداً** |
| `created_by` / `updated_by` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**الشعار:** Spatie Media (collection `logo`) زي باقي الموديلات، مش عمود `image` نصي.

### `payment_method_translations`
`id`, `payment_method_id` (FK cascade), `locale`, `name`, `description` (nullable), timestamps، `unique(payment_method_id, locale)`.

### `payment_method_country` (pivot)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `payment_method_id` | `foreignId → payment_methods` | NOT NULL, `cascadeOnDelete` | |
| `country_id` | `foreignId → countries` | NOT NULL, `cascadeOnDelete` | |
| `min_amount_minor` | `bigint` | nullable | Override اختياري فوق `wallet_settings.min_topup_minor` |
| `max_amount_minor` | `bigint` | nullable | Override اختياري |
| `status` | `boolean` | default `Status::Active` | تعطيل مؤقت للطريقة في الدولة دي من غير حذف الربط |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس:** `unique(payment_method_id, country_id)`.

**قاعدة الإتاحة (تُحسب وقت الطلب، مش مخزّنة):** `status=active AND (is_global OR EXISTS(pivot لدولة المحفظة, status=active))`.

---

## 9. `payment_transactions`

سجل عمليات الدفع مع البوابة — **قبل** ما تتحول لحركة محفظة (زي `sys_online_payment_transactions` في LeeTaxi / `online_payment_transactions` في Jawad، لكن بمعلومات أكتر وقابلة للتدقيق الكامل).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | NOT NULL, unique | |
| `payment_method_id` | `foreignId → payment_methods` | NOT NULL, `restrictOnDelete` | |
| `owner_type` / `owner_id` | `string` / `unsignedBigInteger` | NOT NULL, morph | مين بيشحن (`user`/`provider`) |
| `wallet_id` | `foreignId → wallets` | nullable, `nullOnDelete` | بيتحدد وقت الاكتمال الناجح |
| `country_id` | `foreignId → countries` | NOT NULL, `restrictOnDelete` | |
| `currency_id` | `foreignId → currencies` | NOT NULL, `restrictOnDelete` | |
| `requested_amount_minor` | `bigint` | NOT NULL, CHECK `> 0` | `paid_amount` (فصل 12) — القيمة **المحلية** اللي هتتحسب عليها المحفظة دايماً (راجع بند 9.3) |
| `status` | `string` (enum `pending`/`paid`/`failed`/`expired`/`refunded`) | NOT NULL, default `pending` | |
| `gateway_reference` | `string` | nullable, index | معرف العملية عند البوابة (`paymentId`/`trackId`...) |
| `gateway_invoice_id` | `string` | nullable | |
| `fee_rule_id` | `foreignId → wallet_fee_rules` | nullable, `nullOnDelete` | لقطة القاعدة وقت الـ `quote` (فصل 12) |
| `fee_percent` | `decimal(8,4)` | nullable | لقطة النسبة |
| `quoted_net_amount_minor` | `bigint` | nullable | الصافي المتوقع (بعد الرسوم أو زائد الهدية) |
| `quoted_bonus_amount_minor` | `bigint` | nullable | قيمة الهدية المتوقعة (لو فيه) |
| `raw_request` | `json` | nullable | **آخر** طلب اتبعت للبوابة (لقطة سريعة للعرض بس — التاريخ الكامل في `payment_gateway_logs`، بند 9.2) |
| `raw_response` | `json` | nullable | **آخر** رد من البوابة (نفس الملحوظة) |
| `failure_reason` | `string` | nullable | |
| `expires_at` | `timestamp` | nullable | بعده العملية `pending` تتحول `expired` تلقائي (أمر مجدول) |
| `reconciliation_attempts` | `unsignedInteger` | default `0` | عدد مرات محاولة "تسوية" العملية يدوي (بند 9.3) |
| `last_reconciled_at` | `timestamp` | nullable | |
| `idempotency_key` | `string` | NOT NULL, **unique** | يمنع معالجة نفس العملية مرتين (webhook مكرر، تسوية يدوية متزامنة) |
| `request_hash` | `char(64)` | NOT NULL | SHA-256 لمحتوى طلب الشحن. نفس `idempotency_key` بـ hash مختلف = 409 (قسم 0) |
| `processed_at` | `timestamp` | nullable | لحظة تحويل العملية لحركة محفظة فعلياً |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس:** `index(status)`, `index(owner_type, owner_id)`, `index(gateway_reference)`, `index(status, expires_at)`.

### 9.1 `webhook_inbox` — أول محطة لأي إشعار جاي من البوابة

**السبب:** الـ webhook بيوصل من البوابة **هي البادئة**، عكس ما إحنا طالبين بياناته. وده بيجيب مشاكل مختلفة عن الشحن العادي: (1) البوابة بترسل نفس الحدث أكتر من مرة أحياناً (retry لو سيرفرنا اتأخر بالرد)، (2) رابط الاستقبال عام، فأي حد نظرياً يقدر يبعت عليه POST مزيّف. الحل: صف بيتسجل **أول حاجة** قبل أي منطق بيزنس، بنفس فلسفة `wallet_transactions` (append-only).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `payment_method_id` | `foreignId → payment_methods` | nullable, `nullOnDelete` | البوابة (لو معروفة وقت الاستلام) |
| `provider_code` | `string` | NOT NULL | `myfatoorah` / `urpay` / `arb`... |
| `event_id` | `string` | NOT NULL | معرف الحدث عند البوابة (مش عندنا) |
| `valid_signature` | `boolean` | NOT NULL | نتيجة التحقق من التوقيع **قبل** أي معالجة |
| `payload` | `json` | NOT NULL | الـ payload الخام كامل |
| `payload_hash` | `char(64)` | NOT NULL | لكشف تغيّر المحتوى مع نفس `event_id` |
| `status` | `string` (enum `received`/`processing`/`processed`/`failed`/`ignored`) | NOT NULL, default `received` | |
| `attempts` | `unsignedInteger` | default `0` | |
| `error` | `text` | nullable | |
| `received_at` | `timestamp` | NOT NULL | |
| `processed_at` | `timestamp` | nullable | |

**فهارس/قيود:** **`unique(provider_code, event_id)`** — ده اللي بيمنع تكرار المعالجة: لو الصف موجود بالفعل، الـ webhook الجديد بيتوقف هنا (رد 200 للبوابة، من غير أي حركة مالية) ومبيوصلش أصلاً لـ `PaymentCompletionService`.

**الفرق عن `idempotency_key` في `payment_transactions`:** ده بيحمي الاتجاه المعاكس. `idempotency_key` بيحمي الطلبات **إحنا بادئينها** (شحن، تحويل)، و`webhook_inbox` بيحمي الإشعارات **البوابة بادئاها**.

### 9.2 `payment_gateway_logs` — دفتر ثابت لكل تفاعل مع البوابة

**السبب:** Jawad كان أصلاً من غير أي تسجيل للطلب/الرد، وبعدين اتضاف عمودين (`request_log`/`response_log`) بـ migration منفصلة لاحقة — يعني الشهور الأولى من العمليات اتفقد تسجيلها. وكمان عمودين بيتكتب عليهم Overwrite بيضيّعوا **تاريخ** المحاولات (لو الأدمن عمل "تسوية" 3 مرات، هيشوف بس آخر مرة). الحل: جدول منفصل **immutable** بنفس فلسفة `wallet_transactions` (فصل 2) — صف جديد لكل تفاعل، من غير تعديل أو حذف.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `payment_transaction_id` | `foreignId → payment_transactions` | NOT NULL, `restrictOnDelete` | |
| `event` | `string` (enum `initiate`/`webhook`/`redirect_verify`/`manual_reconcile`/`refund_attempt`) | NOT NULL | |
| `direction` | `string` (enum `outbound`/`inbound`) | NOT NULL | |
| `http_status` | `unsignedSmallInteger` | nullable | |
| `request_payload` | `json` | nullable | **بدون أي بيانات سرية** (نفس قاعدة `credentials`) |
| `response_payload` | `json` | nullable | |
| `gateway_status_reported` | `string` | nullable | الحالة الخام اللي رجعتها البوابة في التفاعل ده تحديداً |
| `triggered_by_type` / `triggered_by_id` | `string` / `unsignedBigInteger` | nullable, morph | أدمن (لو `manual_reconcile`)، `null` = نظام |
| `created_at` | `timestamp` | NOT NULL, `useCurrent()` | **من غير `updated_at`** — نفس مبدأ `wallet_transactions` |

**فهارس:** `index(payment_transaction_id, created_at)`, `index(event)`.

### 9.3 خطوط حمراء وقرارات محسومة من تجربة LeeTaxi/Jawad الفعلية

هنا بالظبط الجدول اللي كنت فاكره ("Online Transactions" + الكنترولر/السيرفيز في Jawad: `FinanceController::onlineTransactionsApi` + `reconcilePaymentApi`، و`OnlinePaymentTransaction` model، و`ARBPaymentService::inquiryTransaction`). اللي مش موجود خالص في LeeTaxi — لو الـ callback ماجاش، العملية تفضل `Pending` للأبد من غير أي وسيلة تصلّحها.

1. **🔴 الخطر الحقيقي مش تزوير الـ callback — ده أصلاً محلول صح في الكودين:** MyFatoorah (LeeTaxi) بيستعلم من البوابة تاني server-to-server بـ `GetPaymentStatus(paymentId)` بدل ما يثق في أي حاجة جاية في رابط الـ redirect، وARB (Jawad) بيفك تشفير الـ payload (`decryptAndGetTransaction`). **ده Invariant لازم يتفرض على أي Gateway driver جديد:** ممنوع أي driver يقبل حالة "نجاح" من querystring/body من غير تحقق تشفيري أو استعلام server-to-server من نفس البوابة.
2. **🔴 الخطر الحقيقي هو "الـ callback ماجاش خالص":** المستخدم قفل المتصفح، أو الشبكة قطعت، أو الـ webhook فشل. اللي بيحصل:
   - **LeeTaxi:** مفيش حل — العملية تفضل `Pending` للأبد، ومفيش شاشة أدمن أصلاً تشوفها.
   - **Jawad:** شاشة "Online Transactions" (فلاتر + ملخص) + زرار **"Reconcile"** على أي عملية `Pending`: بيستعلم من البوابة تاني (`inquiryTransaction`) وبعدين يستخدم **نفس** `PaymentCompletionService` (idempotent) لو البوابة أكّدت الدفع.
   - **القرار لـ dorr:** نفس فكرة Jawad، إلزامية من أول نسخة (مش إضافة لاحقة): `PaymentGateway::verify()` (موجودة أصلاً في الـ interface، فصل 9 في الخطة) = استعلام server-to-server، وشاشة أدمن "Online Transactions" فيها زرار "تسوية" يستدعيها، وكل محاولة بتتسجل في `payment_gateway_logs` (`event=manual_reconcile`).
3. **🔴 null-guard إجباري على أي callback handler:** كود LeeTaxi فيه `OnlinePaymentTransaction::find($ref)->update(...)` من غير التأكد إن `find()` رجّع حاجة — لو الـ reference مش معروف محلياً، ده بيرمي Fatal Error على endpoint البوابة نفسها بتستناه (ممكن يخلي البوابة تعتبر الـ endpoint معطوب وتوقف تحاول). **القاعدة:** أي callback handler يتعامل مع "معاملة غير معروفة محلياً" كحالة طبيعية — يسجّلها في `payment_gateway_logs` (بدون `payment_transaction_id`، أو بجدول أخطاء منفصل) ويرجّع رد مناسب للبوابة، من غير Exception.
4. **🔴 الذرّية (Atomicity):** تحديث `payment_transactions.status = paid` وإنشاء حركة `wallet_transactions` (`WalletService::credit`) **لازم يحصلوا في نفس الـ `DB::transaction`** بعد `lockForUpdate` على صف `payment_transactions` والتأكد إن حالته لسه `pending`. غير كده Crash في النص = فلوس اتضافت من غير ما العملية تتقفل (فتتعالج تاني) أو العكس.
5. **مصدر المبلغ اللي بيتضاف للمحفظة دايماً هو `requested_amount_minor` المحفوظ محلياً وقت الشحن، مش أي رقم راجع من البوابة أو الـ callback.** (كده فعلاً في الكودين المرجعيين — إحنا بس بنثبّتها كقاعدة صريحة مش سلوك ضمني.)
6. **انتهاء الصلاحية:** أمر مجدول (`payment:expire-stale`) بيحوّل أي `pending` عدّى `expires_at` لـ `expired` — **بس ده مش نهائي**، الأدمن لسه يقدر "يسوّي" عملية `expired` لو البوابة فعلاً أكّدت دفعها متأخر.
7. **رسائل الخطأ:** كل Gateway driver بيوفّر `translateError(code, locale)` بمفاتيح ترجمة (`ar.json`/`en.json`) بدل كلاس أخطاء منفصل زي `ARBErrorMessages` مربوط بلغتين ثابتين بس.

---

## 10. مخطط العلاقات (نصي)

```
countries ──< wallets >── (owner: user | provider | platform)
   │                │
   currency_id      ├──< wallet_transactions >── counterparty_wallet_id (self)
   │                │         │
   │                │         ├── reference (morph: booking/order — لاحقاً)
   │                │         ├── fee_rule_id ──> wallet_fee_rules
   │                │         └── payment_transaction_id ──> payment_transactions
   │                │
   │                ├──< wallet_holds ──> wallet_transaction_id (عند capture)
   │                │         └── reference (morph: withdrawal_request/booking)
   │                │
   ├──< wallet_settings (1:1)
   │
   ├──< payment_method_country >── payment_methods ──< payment_method_translations
   │                                      │
   │                                      └──< payment_transactions ──< payment_gateway_logs
   │                                                    ▲
   │                                          webhook_inbox (dedup قبل ما يوصل هنا)
   │
   └──< wallet_pins (1:1 owner)

wallet_fee_rules ──< wallet_fee_rule_translations
financial_categories ──< financial_category_translations
financial_categories ──< financial_entries ──> wallet_transactions (nullable)

wallets ──< withdrawal_requests >── withdrawal_methods (owner: provider)
withdrawal_requests ──> hold_id ──> wallet_holds
```

---

## 11. قرارات محسومة (بعد مراجعة `dorr-wallet-schema.pdf`) + افتراضات لسه مفتوحة

### محسومة (مش افتراضات بقى)
| # | القرار | مكانه |
|---|---|---|
| 1 | موديول `Modules/Wallet` جديد (مش `app/`) | قسم 0 |
| 2 | `bigint` minor units بلاحقة `_minor` (مش `decimal`) | قسم 0 |
| 3 | `unique(id, country_id, currency_id)` كـ composite FK يمنع تضارب الدولة/العملة على مستوى الـ DB | جدول 1، 2 |
| 4 | `idempotency_key` + `request_hash` معاً، 409 عند التعارض | قسم 0، جداول 2، 9 |
| 5 | قفل أي عملية بتلمس أكتر من محفظة بترتيب `id` تصاعدي دايماً | قسم 0 |
| 6 | `webhook_inbox` جدول منفصل لاستقبال ودمج (dedup) أي webhook قبل المعالجة | جدول 9.1 |
| 7 | `wallet_pins` — PIN مالي منفصل عن تسجيل الدخول، إجباري على: الشحن، التحويل، دفع الخدمة، السحب، وتعديل/إضافة وسيلة سحب | جدول 1.2 |
| 8 | `payment_gateway_logs` جدول منفصل immutable لكل تفاعل مع البوابة | جدول 9.2 |
| 9 | `PaymentGateway::verify()` + شاشة "تسوية" إجباريين من أول نسخة | جدول 9.3 |
| 10 | **مرفوض:** نموذج Double-Entry (`ledger_accounts`/`ledger_entries`) من `dorr-wallet-schema.pdf` — الفلاج البسيط (`withdrawable`/`spend_only`) كافي لاحتياجنا، مفيش داعي لتعقيده | فصل 10 (wallet-plan.md) |
| 11 | PIN إجباري على تعديل/إضافة وسيلة سحب زي السحب بالظبط | جدول 1.2 |
| 12 | إنشاء أول PIN **Lazy** (أول احتياج ليه، مش وقت التسجيل)، بإدخال مزدوج (PIN + تأكيد) على الموبايل | جدول 1.2 |
| 13 | **`wallet_holds` (Hold/Capture) مبني من النواة**، ومُوحّد مع حجز طلبات السحب (بدل `reserved_withdrawable_minor` المباشر) — سعر ثابت = `debit()` مباشر، سعر تقديري = `hold()` ثم `capture()` | جدول 2.1 |

### لسه افتراضات مفتوحة
| # | الافتراض في الملف ده | مكانه | بديل لو رفضته |
|---|---|---|---|
| 1 | محفظة النظام `owner_type=platform, owner_id=0` بدون موديول حقيقي | جدول 1، بند 1.1 | جدول `platform_accounts` مستقل |
| 2 | `wallet_transactions` من غير `updated_at` (immutability على مستوى الـ schema) | جدول 2 | `updated_at` عادي + منع التعديل بالكود بس |
| 3 | `max_uses_per_owner` بيتعدّ من `wallet_transactions` مباشرة (من غير جدول عدّاد) | جدول 4 | جدول `wallet_fee_rule_usages` منفصل |
| 4 | فئات `financial_categories` الأساسية بس (4 فئات) في النواة، والباقي لاحقاً | جدول 5 | تاكسونومي كامل من أول يوم (لسه مطلوب منك، سؤال #9 في wallet-plan.md) |
| 5 | `withdrawal_methods` لـ Provider بس دلوقتي | جدول 6 | تشمل User من الأول |
| 6 | الإيصال والشعار عبر Spatie Media مش أعمدة نصية | جداول 7، 8 | عمود `string` لمسار الملف |
| 7 | `pin_reset` (استرجاع PIN منسي) هيتصمم كتفصيل لاحق مش في النواة الأولى | جدول 1.2 | تصميمه كامل من أول يوم (SMS/بريد/مستند زي الملف المرجعي) |

---

## 12. أفكار/تعديلات إضافية (هتتضاف هنا)
<!-- سيب المكان ده لأي تعديل على البنية بعد النقاش. -->

-
