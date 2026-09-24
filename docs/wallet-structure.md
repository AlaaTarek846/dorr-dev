# Wallet — بنية قاعدة البيانات (DRAFT)

> **الحالة: غير معتمدة.** ده تصميم تفصيلي مبني على [wallet-plan.md](wallet-plan.md). فيه أماكن اتحطت فيها قيمة افتراضية معقولة (متعلّم عليها **افتراض**) عشان الجدول يبقى قابل للمراجعة الكاملة — **مش قرار نهائي**. راجع قسم "الافتراضات المفتوحة" في آخر الملف قبل أي migration فعلي.
> **ممنوع تنفيذ أي كود أو migration من الملف ده** قبل الاعتماد (حسب [AGENTS.md](../AGENTS.md)).

آخر تحديث: 2026-09-22 — مبني على wallet-plan.md (تحديث 4).

---

## 0. قواعد عامة قبل الجداول

### أماكن الكود (افتراض — محتاج تأكيدك، سؤال #10 في wallet-plan.md)
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
- **المبالغ:** `decimal(19,4)` لكل عمود مالي (مش `float`). أربع خانات عشرية تغطي أي عملة (`currencies.decimal_places` بيحدد العرض بس، مش التخزين). **افتراض — بديل عن minor units (integers)، سؤال #12 في wallet-plan.md.**
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
| `withdrawable_balance` | `decimal(19,4)` | default `0` | الرصيد القابل للسحب (ممكن يبقى سالب لو مسموح دين، فصل 14) |
| `spend_only_balance` | `decimal(19,4)` | default `0`, CHECK `>= 0` | رصيد Hold — استخدام داخل التطبيق بس |
| `reserved_withdrawable` | `decimal(19,4)` | default `0`, CHECK `>= 0` | محجوز لطلبات سحب `Pending` |
| `status` | `boolean` | default `Status::Active` | تجميد المحفظة (أدمن) |
| `last_reconciled_at` | `timestamp` | nullable | آخر مرة أمر `wallet:reconcile` أكّد إن الرصيد مطابق للحركات |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس/قيود:**
- `unique(owner_type, owner_id, country_id)`
- `index(country_id)`, `index(currency_id)`, `index(owner_type, owner_id)`

**بند 1.1 — محفظة النظام (`platform`):** مفيش موديل Eloquent حقيقي لـ "النظام"، فبدل جدول إضافي، `owner_type = 'platform'` و`owner_id` بيتثبّت على `0`. محفظة واحدة لكل دولة (`unique(owner_type, owner_id, country_id)` بيسمح بيها لأن `owner_id=0` ثابت مش NULL). بتتعمل seed تلقائي عند تفعيل أي دولة. **افتراض — بديل لسؤال #4 (حسابات النظام) في wallet-plan.md.**

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
| `amount` | `decimal(19,4)` | NOT NULL, CHECK `> 0` | موجب دايماً؛ الاتجاه من `direction` |
| `currency_id` | `foreignId → currencies` | NOT NULL, `restrictOnDelete` | لقطة من المحفظة |
| `country_id` | `foreignId → countries` | NOT NULL, `restrictOnDelete` | لقطة من المحفظة |
| `balance_after` | `decimal(19,4)` | NOT NULL | رصيد الـ `bucket` ده بعد الصف مباشرة |
| `total_balance_after` | `decimal(19,4)` | NOT NULL | إجمالي المحفظة (`withdrawable + spend_only`) بعد الصف |
| `reference_type` / `reference_id` | `string` / `unsignedBigInteger` | nullable, morph | مرجع خارجي (حجز/طلب/فاتورة — لاحقاً) |
| `counterparty_wallet_id` | `foreignId → wallets` | nullable, `restrictOnDelete` | محفظة الطرف التاني في التحويل |
| `reverses_transaction_id` | `foreignId → wallet_transactions` | nullable, self, `restrictOnDelete` | لو الصف ده عكس صف قديم |
| `fee_rule_id` | `foreignId → wallet_fee_rules` | nullable, `nullOnDelete` | لو الصف رسوم/هدية شحن |
| `fee_percent` | `decimal(8,4)` | nullable | النسبة المطبّقة فعلياً (لقطة، فصل 12) |
| `payment_transaction_id` | `foreignId → payment_transactions` | nullable, `nullOnDelete` | لو الصف ناتج عن شحن أونلاين |
| `idempotency_key` | `string` | nullable, **unique** | مفتاح منع التكرار (على الصف "الرئيسي" في العملية بس) |
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

**CHECK constraints:** `amount > 0`، `bucket IN ('withdrawable','spend_only')`، `direction IN ('credit','debit')`.

**كيف نعرف إن صف اتعكس؟** بالبحث عن صف تاني بـ `reverses_transaction_id = هذا الـ id` — من غير أي تعديل على الصف الأصلي.

---

## 3. `wallet_settings`

إعداد واحد **لكل دولة** (مش سطر عام واحد زي `::first()` في المشروعين المرجعيين).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `country_id` | `foreignId → countries` | NOT NULL, **unique**, `cascadeOnDelete` | |
| `min_topup_amount` | `decimal(19,4)` | nullable | أقل مبلغ شحن |
| `max_topup_amount` | `decimal(19,4)` | nullable | أعلى مبلغ شحن |
| `min_withdrawal_amount` | `decimal(19,4)` | nullable | أقل مبلغ سحب |
| `max_withdrawal_amount` | `decimal(19,4)` | nullable | أعلى مبلغ سحب لكل طلب |
| `transfer_max_per_transaction` | `decimal(19,4)` | nullable | أقصى تحويل لكل عملية |
| `transfer_max_per_day` | `decimal(19,4)` | nullable | أقصى تحويل يومي |
| `transfer_max_per_month` | `decimal(19,4)` | nullable | أقصى تحويل شهري |
| `transfers_enabled` | `boolean` | default `false` | تفعيل التحويل بين المستخدمين في الدولة دي (سؤال مفتوح، فصل 16 بند التحويل) |
| `min_allowed_balance_provider` | `decimal(19,4)` | NOT NULL, default `0` | حد الدين **signed** لمقدم الخدمة (فصل 14). مثلاً `-100` |
| `min_allowed_balance_user` | `decimal(19,4)` | NOT NULL, default `0` | حد الدين **signed** للمستخدم (نتيجة عقوبات) |
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
| `min_amount` | `decimal(19,4)` | nullable | أقل قيمة رسوم/هدية بالمطلق لكل عملية |
| `max_amount` | `decimal(19,4)` | nullable | أقصى قيمة رسوم/هدية بالمطلق لكل عملية (cap) |
| `starts_at` | `timestamp` | nullable | `null` = بدأ فعلاً |
| `ends_at` | `timestamp` | nullable | `null` = مفتوح |
| `max_uses_per_owner` | `unsignedInteger` | nullable | يتحقق بعدّ صفوف `wallet_transactions` بنفس `fee_rule_id` لنفس المحفظة — **مفيش جدول عدّاد منفصل** |
| `budget_total` | `decimal(19,4)` | nullable | أقصى تكلفة إجمالية للعرض (هدايا) |
| `budget_used` | `decimal(19,4)` | NOT NULL, default `0` | بيتحدّث ذرّياً (`increment` جوه نفس الـ transaction) كل مرة تتطبّق القاعدة |
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
| `amount` | `decimal(19,4)` | NOT NULL, CHECK `> 0` | |
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
| `amount` | `decimal(19,4)` | NOT NULL, CHECK `> 0` | |
| `status` | `string` (enum `pending`/`approved`/`rejected`) | NOT NULL, default `pending` | |
| `note` | `text` | nullable | ملاحظة الأدمن عند القبول |
| `rejection_reason` | `text` | nullable, **NOT NULL لو الحالة `rejected`** (يتفرض بالـ Request) | |
| `reviewed_by` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `reviewed_at` | `timestamp` | nullable | |
| `wallet_transaction_id` | `foreignId → wallet_transactions` | nullable, `nullOnDelete` | الصف الناتج عند القبول (`type = withdrawal`) |
| `created_at` / `updated_at` | `timestamp` | | |
| `deleted_at` | `timestamp` | softDeletes | |

**الإيصال:** عبر Spatie Media Library (collection `receipt`, إجباري عند `approve`) — مش عمود منفصل، زي باقي ملفات المشروع (`HasMediaTrait`).

**فهارس:** `index(wallet_id, status)`, `index(status)`.

**تدفق الحجز (بدون جدول إضافي):**
1. إنشاء الطلب → داخل `DB::transaction` + `lockForUpdate` على `wallets`: تحقق `withdrawable_balance − reserved_withdrawable ≥ amount` (وضمن `min/max_withdrawal_amount` و`min_allowed_balance`) → `reserved_withdrawable += amount` → إنشاء الصف `pending`.
2. قبول → قفل تاني، تحقق الرصيد لسه كافي → صف `wallet_transactions` (`type=withdrawal`, `bucket=withdrawable`, `direction=debit`) → `withdrawable_balance -= amount`، `reserved_withdrawable -= amount` → الطلب `approved` + `wallet_transaction_id`.
3. رفض → `reserved_withdrawable -= amount` (تحرير الحجز، من غير أي حركة مالية) → الطلب `rejected` + `rejection_reason` إجباري.

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
| `min_amount` | `decimal(19,4)` | nullable | Override اختياري فوق `wallet_settings.min_topup_amount` |
| `max_amount` | `decimal(19,4)` | nullable | Override اختياري |
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
| `requested_amount` | `decimal(19,4)` | NOT NULL, CHECK `> 0` | `paid_amount` (فصل 12) — القيمة **المحلية** اللي هتتحسب عليها المحفظة دايماً (راجع بند 9.2) |
| `status` | `string` (enum `pending`/`paid`/`failed`/`expired`/`refunded`) | NOT NULL, default `pending` | |
| `gateway_reference` | `string` | nullable, index | معرف العملية عند البوابة (`paymentId`/`trackId`...) |
| `gateway_invoice_id` | `string` | nullable | |
| `fee_rule_id` | `foreignId → wallet_fee_rules` | nullable, `nullOnDelete` | لقطة القاعدة وقت الـ `quote` (فصل 12) |
| `fee_percent` | `decimal(8,4)` | nullable | لقطة النسبة |
| `quoted_net_amount` | `decimal(19,4)` | nullable | الصافي المتوقع (بعد الرسوم أو زائد الهدية) |
| `quoted_bonus_amount` | `decimal(19,4)` | nullable | قيمة الهدية المتوقعة (لو فيه) |
| `raw_request` | `json` | nullable | **آخر** طلب اتبعت للبوابة (لقطة سريعة للعرض بس — التاريخ الكامل في `payment_gateway_logs`، بند 9.1) |
| `raw_response` | `json` | nullable | **آخر** رد من البوابة (نفس الملحوظة) |
| `failure_reason` | `string` | nullable | |
| `expires_at` | `timestamp` | nullable | بعده العملية `pending` تتحول `expired` تلقائي (أمر مجدول) |
| `reconciliation_attempts` | `unsignedInteger` | default `0` | عدد مرات محاولة "تسوية" العملية يدوي (بند 9.2) |
| `last_reconciled_at` | `timestamp` | nullable | |
| `idempotency_key` | `string` | NOT NULL, **unique** | يمنع معالجة نفس العملية مرتين (webhook مكرر، تسوية يدوية متزامنة) |
| `processed_at` | `timestamp` | nullable | لحظة تحويل العملية لحركة محفظة فعلياً |
| `created_at` / `updated_at` | `timestamp` | | |

**فهارس:** `index(status)`, `index(owner_type, owner_id)`, `index(gateway_reference)`, `index(status, expires_at)`.

### 9.1 `payment_gateway_logs` — دفتر ثابت لكل تفاعل مع البوابة

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

### 9.2 خطوط حمراء وقرارات محسومة من تجربة LeeTaxi/Jawad الفعلية

هنا بالظبط الجدول اللي كنت فاكره ("Online Transactions" + الكنترولر/السيرفيز في Jawad: `FinanceController::onlineTransactionsApi` + `reconcilePaymentApi`، و`OnlinePaymentTransaction` model، و`ARBPaymentService::inquiryTransaction`). اللي مش موجود خالص في LeeTaxi — لو الـ callback ماجاش، العملية تفضل `Pending` للأبد من غير أي وسيلة تصلّحها.

1. **🔴 الخطر الحقيقي مش تزوير الـ callback — ده أصلاً محلول صح في الكودين:** MyFatoorah (LeeTaxi) بيستعلم من البوابة تاني server-to-server بـ `GetPaymentStatus(paymentId)` بدل ما يثق في أي حاجة جاية في رابط الـ redirect، وARB (Jawad) بيفك تشفير الـ payload (`decryptAndGetTransaction`). **ده Invariant لازم يتفرض على أي Gateway driver جديد:** ممنوع أي driver يقبل حالة "نجاح" من querystring/body من غير تحقق تشفيري أو استعلام server-to-server من نفس البوابة.
2. **🔴 الخطر الحقيقي هو "الـ callback ماجاش خالص":** المستخدم قفل المتصفح، أو الشبكة قطعت، أو الـ webhook فشل. اللي بيحصل:
   - **LeeTaxi:** مفيش حل — العملية تفضل `Pending` للأبد، ومفيش شاشة أدمن أصلاً تشوفها.
   - **Jawad:** شاشة "Online Transactions" (فلاتر + ملخص) + زرار **"Reconcile"** على أي عملية `Pending`: بيستعلم من البوابة تاني (`inquiryTransaction`) وبعدين يستخدم **نفس** `PaymentCompletionService` (idempotent) لو البوابة أكّدت الدفع.
   - **القرار لـ dorr:** نفس فكرة Jawad، إلزامية من أول نسخة (مش إضافة لاحقة): `PaymentGateway::verify()` (موجودة أصلاً في الـ interface، فصل 9 في الخطة) = استعلام server-to-server، وشاشة أدمن "Online Transactions" فيها زرار "تسوية" يستدعيها، وكل محاولة بتتسجل في `payment_gateway_logs` (`event=manual_reconcile`).
3. **🔴 null-guard إجباري على أي callback handler:** كود LeeTaxi فيه `OnlinePaymentTransaction::find($ref)->update(...)` من غير التأكد إن `find()` رجّع حاجة — لو الـ reference مش معروف محلياً، ده بيرمي Fatal Error على endpoint البوابة نفسها بتستناه (ممكن يخلي البوابة تعتبر الـ endpoint معطوب وتوقف تحاول). **القاعدة:** أي callback handler يتعامل مع "معاملة غير معروفة محلياً" كحالة طبيعية — يسجّلها في `payment_gateway_logs` (بدون `payment_transaction_id`، أو بجدول أخطاء منفصل) ويرجّع رد مناسب للبوابة، من غير Exception.
4. **🔴 الذرّية (Atomicity):** تحديث `payment_transactions.status = paid` وإنشاء حركة `wallet_transactions` (`WalletService::credit`) **لازم يحصلوا في نفس الـ `DB::transaction`** بعد `lockForUpdate` على صف `payment_transactions` والتأكد إن حالته لسه `pending`. غير كده Crash في النص = فلوس اتضافت من غير ما العملية تتقفل (فتتعالج تاني) أو العكس.
5. **مصدر المبلغ اللي بيتضاف للمحفظة دايماً هو `requested_amount` المحفوظ محلياً وقت الشحن، مش أي رقم راجع من البوابة أو الـ callback.** (كده فعلاً في الكودين المرجعيين — إحنا بس بنثبّتها كقاعدة صريحة مش سلوك ضمني.)
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
   ├──< wallet_settings (1:1)
   │
   ├──< payment_method_country >── payment_methods ──< payment_method_translations
   │                                      │
   │                                      └──< payment_transactions ──< payment_gateway_logs

wallet_fee_rules ──< wallet_fee_rule_translations
financial_categories ──< financial_category_translations
financial_categories ──< financial_entries ──> wallet_transactions (nullable)

wallets ──< withdrawal_requests >── withdrawal_methods (owner: provider)
```

---

## 11. الافتراضات المفتوحة (لازم تأكيدك قبل أي migration)

| # | الافتراض في الملف ده | مكانه | بديل لو رفضته |
|---|---|---|---|
| 1 | موديول `Modules/Wallet` جديد | قسم 0 | كل الكود جوه `app/` |
| 2 | `decimal(19,4)` لكل المبالغ | قسم 0 | أعداد صحيحة (minor units) |
| 3 | محفظة النظام `owner_type=platform, owner_id=0` بدون موديول حقيقي | جدول 1، بند 1.1 | جدول `platform_accounts` مستقل |
| 4 | `wallet_transactions` من غير `updated_at` (immutability على مستوى الـ schema) | جدول 2 | `updated_at` عادي + منع التعديل بالكود بس |
| 5 | `max_uses_per_owner` بيتعدّ من `wallet_transactions` مباشرة (من غير جدول عدّاد) | جدول 4 | جدول `wallet_fee_rule_usages` منفصل |
| 6 | فئات `financial_categories` الأساسية بس (4 فئات) في النواة، والباقي لاحقاً | جدول 5 | تاكسونومي كامل من أول يوم (لسه مطلوب منك، سؤال #9 في wallet-plan.md) |
| 7 | `withdrawal_methods` لـ Provider بس دلوقتي | جدول 6 | تشمل User من الأول |
| 8 | الإيصال والشعار عبر Spatie Media مش أعمدة نصية | جداول 7، 8 | عمود `string` لمسار الملف |
| 9 | `payment_gateway_logs` جدول منفصل immutable (بدل `raw_request`/`raw_response` بس) | جدول 9.1 | الاكتفاء بعمودي اللقطة الأخيرة على `payment_transactions` |
| 10 | تفعيل `PaymentGateway::verify()` + شاشة "تسوية" إجباريين من أول نسخة (مش تحسين لاحق) | جدول 9.2 | تأجيلها لمرحلة تالية (خطر: عمليات `Pending` عالقة بلا حل، زي LeeTaxi) |

---

## 12. أفكار/تعديلات إضافية (هتتضاف هنا)
<!-- سيب المكان ده لأي تعديل على البنية بعد النقاش. -->

-
