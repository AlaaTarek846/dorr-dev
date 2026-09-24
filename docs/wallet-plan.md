# Wallet Plan — مسودة (DRAFT)

> **الحالة: غير معتمدة.** ده ملف بحث + اتجاه مقترح. هيتزود عليه أفكار تانية قبل أي تنفيذ.
> **ممنوع تنفيذ أي كود أو migration من الملف ده** قبل ما يتم اعتماده (حسب [AGENTS.md](../AGENTS.md): أي حاجة مش واضحة = **NEEDS-DECISION**).

**الأقسام:** 1–5 دراسة LeeTaxi وJawad · 6 توجيه المشروع · 7 المحفظة لكل دولة · 8 تحديد الدولة · 9 طرق الدفع · **10 🔴 فلاج قابلية السحب (أهم قسم)** · 11 تخزين الرصيد (محفظة + حركات) · 12 رسوم وهدايا (نسبة ممكن تبقى سالبة) · 13 الحسابات المالية للنظام (إيرادات/مصروفات) · 14 رصيد استقبال الطلبات (حد الدين) · 15 الاتجاه المقترح والمراحل · 16 نقاط للنقاش · 17 أفكار إضافية

آخر تحديث: 2026-09-22 (تحديث 4: صيغة الرسوم/الهدية النهائية، تكامل بوابات الدفع، الحسابات المالية للنظام، حد الدين لكل الأطراف)
المصادر: `C:\laragon\www\LeeTaxi` و `C:\laragon\www\Jawad` (قراءة فقط، مفيش أي تعديل عليهم).

---

## 1. المحفظة في LeeTaxi

### الفكرة العامة
المحفظة **دفتر حركات (ledger)**. مفيش عمود رصيد مخزّن. كل صف هو حركة واحدة، والرصيد بيتحسب كل مرة بجمع الحركات.

### الجداول
| الجدول | الموديول | الغرض |
|---|---|---|
| `us_wallets` | User | حركات محفظة العميل (`us_user_id`) |
| `drv_wallets` | Driver | حركات محفظة السواق (`driver_id`) |
| `str_wallets` / `sr_wallets` / `rest_wallets` | Store / Showroom / Rest | محفظة لكل موديول (بنفس الفكرة) |
| `us_wallet_transaction_settings` | User | حدود التحويل: `max_limit_per_transaction` (3000) / `per_day` (10000) / `per_month` (100000) |
| `drv_withdrawl_requests` | Driver | طلبات السحب (`status`, `amount`, `driver_withdrawal_method_id`) |
| `drv_withdrawal_methods` | Driver | وسائل السحب (بيانات JSON: بنك أو رقم محفظة) |
| `sys_online_payment_transactions` | System | عمليات الدفع الأونلاين (MyFatoorah) قبل ما تتحول لحركة محفظة |
| `sys_invoices` / `sys_invoice_payments` | System | فواتير الحجز، والمحفظة طريقة دفع (`payment_method_id = 1`) |

أعمدة صف الحركة: `balance` (المبلغ نفسه رغم الاسم)، `type` (enum)، `invoice_id`, `transaction_id`، مرجع polymorphic (`model_type`/`model_id`) للحجز اللي عمل الحركة، `service_type`، `notes` (JSON)، `transfer_wallet_number` (للتحويل بين العملاء)، `softDeletes`.

### حساب الرصيد
في `User::getWalletAmountAttribute()` و `Driver::getWalletAmountAttribute()`:
`الرصيد = مجموع(أنواع الإضافة) − مجموع(أنواع الخصم)`. الاتجاه **مش مخزّن في الصف**، بيتحدد من نوع الحركة (`type`) وقائمة الأنواع مكتوبة يدوي جوه الدالة.

### أنواع الحركات (enums منفصلة لكل طرف)
- **العميل (`Modules\User\Enums\WalletStatus`):** إضافة: `Added`, `Refund`, `Transfer From`, `Charged By Driver`, `Referral Code`, `Convert Point` — خصم: `Paid`, `Penalty`, `Transfer To`.
- **السواق (`Modules\Driver\Enums\WalletStatus`):** إضافة: `Added`, `Refund`, `Trip Amount`, `Referral Code`, `Convert Point` — خصم: `Paid`, `Penalty`, `Charge Client Wallet`, `Withdrawal`, `Paid Prev Client Debt`.
- كل enum فيه `color()` و `mobileColor()` و `translated()` و `icon()` للعرض.

### التدفقات
1. **الشحن أونلاين:** `WalletController@chargeWallet` ← `PaymentInterface::payOnline` (MyFatoorah) ← callback نجاح ← لو `invoice_id` مش موجود في المحفظة يتعمل صف `Added` + notification.
2. **الدفع من المحفظة:** `useWallet()` في `BookingGeneralHelperFunctions` — لو `use_wallet` والرصيد > 0 يخصم `min(المتبقي, الرصيد)` كحركة `Paid`، ويعمل Invoice + `InvoicePayment` بـ `payment_method_id = 1`. (الدفع الجزئي مدعوم.)
3. **الاسترجاع (Refund):** trait اسمه `HandlesRefunds` — لو الحجز اتلغى وفيه مبلغ مدفوع، يعمل حركة `Refund` ويغيّر حالة الفاتورة لـ `Returned To Wallet`.
4. **تحويل بين العملاء:** حركتين (`Transfer To` للمرسل، `Transfer From` للمستقبل) عن طريق `wallet_number` لكل مستخدم، مع حدود يومية/شهرية/لكل عملية للمرسل **وللمستقبل**، وإشعار للطرفين.
5. **السواق:** عند نهاية الرحلة يتخصم منه عمولة التطبيق + الضرائب + الرسوم (`Paid`)، ولو العميل دفع مقدم من المحفظة بيتضاف له `Trip Amount`. لو الكاش أكتر من الفاتورة السواق ممكن "يشحن محفظة العميل" (`Charge Client Wallet` عند السواق ↔ `Charged By Driver` عند العميل).
6. **حد الدين:** لو رصيد السواق سالب وعدّى `minimum_amount_to_receive_request` يوصله إشعار ومش بيستقبل طلبات كاش.
7. **السحب:** السواق يعمل طلب (`Pending`) بعد التحقق: الرصيد كافي، مش أكتر من `limit_withdrawal_request_wallet`، عنده وسيلة سحب مفضلة، ومفيش طلب `Pending` تاني. الأدمن يقبل/يرفض؛ عند القبول يتأكد من الرصيد تاني ويعمل حركة `Withdrawal`.
8. **الأدمن (dashboard):** `WalletTransactionsController` (عرض التحويلات) + `DriverWithdrawalRequestController` + `UserWalletTransactionSettingController` (حدود التحويل)، محمية بـ permissions (`wallet transaction read`, `driver withdrawal request read/edit`).
9. **الإشعارات والرسائل:** النصوص بتتخزن كـ **مفتاح ترجمة + متغيرات** جوه `notes` (JSON)، والـ Resource بيترجمها وقت العرض.

---

## 2. المحفظة في Jawad

### الفكرة العامة
نفس فكرة الـ ledger، لكن **بشكل أبسط ومقسوم لطرفين فقط**: Rider (راكب) و Driver (سواق).

### الجداول
| الجدول | الغرض |
|---|---|
| `rider_wallets` | حركات الراكب (`rider_id`, `balance`, `type`, `invoice_id`, `transaction_id`, `travel_id`, `notes`, `softDeletes`) |
| `driver_wallets` | حركات السواق (نفس الأعمدة، و`travel_id` بقى nullable) |
| `wallet_settings` | إعدادات: `min_amount_to_add`, `max_amount_to_add`, `driver_min_to_get_ride`, `min_limit_withdrawal_request_wallet` (100), `max_debt_for_cash` |
| `withdrawl_requests` | طلبات السحب: `method` (bank / wallet), `bank_details`, `phone`, `amount`, `note`, **`receipt_image`** |
| `online_payment_transactions` | عمليات الدفع (بوابات ARB / UR / Tap) |
| `invoices` / `invoice_payments` | فواتير الرحلات |
| `wallets` + `wallet_transactions` | جداول polymorphic قديمة، **شكلها مش مستخدم في حساب الرصيد** (الرصيد بييجي من `*_wallets`) — يتأكد قبل ما نعتبرها ميتة |

### حساب الرصيد
نفس الأسلوب: `Rider::getWalletAmountAttribute()` و `Driver::getWalletAmountAttribute()` بيجمعوا بحسب قوائم الأنواع.

### أنواع الحركات
- **Rider (`RiderWalletStatus`):** إضافة: `Added`, `Refund`, `Charged By Driver` — خصم: `Paid`, `Penalty`. (وفيه `Referral Reward` كنوع.)
- **Driver (`DriverWalletStatus`):** إضافة: `Added`, `Trip Amount`, `Referral Reward`, `Discount Compensation`, `Driver Reward`, `Returned to Wallet` — خصم: `Penalty`, `Charge Client Wallet`, `Withdrawal`, `Paid`.

### التدفقات (الفروق عن LeeTaxi)
1. **إكمال الدفع مركزي:** `OnlinePaymentCompletionService::completeSuccessfulPayment()` بيفرّع حسب `model_type` (Travel / Driver / Rider) وبيعمل idempotency (لو `transaction_id` اتعالج قبل كده يرجّع `already_processed`). ده أنضف من LeeTaxi اللي الـ callback فيه بيكرر المنطق.
2. **حدود الشحن:** `min_amount_to_add` / `max_amount_to_add` من `wallet_settings`.
3. **مفيش تحويل بين الأشخاص** (مفيش `wallet_number`).
4. **دين الراكب:** `rider_debt_paid` بيتخصم من الاسترجاع (`HandlesRefunds`) — لو كل المبلغ كان دين مفيش refund.
5. **حد السواق:** `driver_min_to_get_ride` بيحدد أقصى مبلغ كاش ممكن السواق يقبله على حسب رصيده (`allowedPaidAmount = min + wallet_amount`).
6. **السحب:** أدمن بيقبل بـ **رفع صورة إيصال إجباري** + ملاحظة؛ الرفض بملاحظة إجبارية. الحد هنا **حد أدنى** للسحب (LeeTaxi كان حد أقصى).
7. **إدارة العقوبات (Penalties):** شاشة أدمن لعرض عقوبات السواقين والركاب، و**عكس العقوبة** (`revertDriverPenalty`) بـ soft delete للحركة (بتظهر في "المعكوسة" بـ `onlyTrashed`)، وأمر artisan `RevertPenalties` لعكس جماعي من تاريخ معين.
8. **أنواع إضافية:** تعويض خصم (`DiscountCompensationService`)، مكافآت السواق (`DriverRewardService`)، إحالة (`ReferralService`).
9. **الأدمن:** شاشة `wallet-transactions` (Vue) للسواقين والركاب، وفي تفاصيل كل راكب/سواق مودال لحركات المحفظة، وإعدادات المحفظة في Settings.

---

## 3. مقارنة سريعة

| النقطة | LeeTaxi | Jawad |
|---|---|---|
| تخزين الرصيد | مجموع الحركات كل مرة | نفس الشيء |
| أطراف المحفظة | User / Driver / Store / Showroom / Rest | Rider / Driver |
| تحويل بين المستخدمين | ✅ مع حدود يومي/شهري | ❌ |
| الدفع الأونلاين | MyFatoorah (`PaymentInterface`) | ARB / UR / Tap + `OnlinePaymentCompletionService` |
| Idempotency للشحن | فحص `invoice_id` | فحص `transaction_id` + حالة العملية |
| سحب السواق | وسائل سحب محفوظة، حد أقصى | بنك/محفظة مباشرة، حد أدنى، إيصال إجباري |
| عكس العقوبات | ❌ | ✅ (soft delete + أمر artisan) |
| إعدادات | حدود التحويل + حد الدين في `driver_settings` | `wallet_settings` واحد |

---

## 4. نقاط ضعف لازم نتجنبها (لقيتها في الكودين)

1. **الرصيد بيتحسب بالجمع ومفيش قفل (lock):** الفحص ثم الإدخال (`check-then-insert`) من غير transaction/`lockForUpdate` = ممكن طلبين متزامنين يصرفوا نفس الرصيد.
2. **الاتجاه (إضافة/خصم) مستنتج من `type`** وقوائم مكتوبة يدوي جوه دوال الرصيد؛ نوع جديد يتنسى في القائمة = رصيد غلط بدون أي خطأ.
3. **الحقل اسمه `balance` وهو في الحقيقة مبلغ الحركة** — مسمّى مضلل، ومفيش `balance_after` نراجع بيه.
4. **Enums مكررة لكل طرف** (User/Driver/Rider) بنفس الأسماء ومنطق متكرر.
5. **`float('amount', 5, 2)` في جدول طلبات السحب (الكودين):** لو النسخة بتحوله لـ `FLOAT(5,2)` يبقى الحد الأقصى 999.99. **يتأكد حسب نسخة Laravel** ومنستخدمش float للفلوس أصلاً.
6. **العملة مكتوبة ثابتة "SAR"** في الرسائل والكود، ومفيش `currency` على الحركة.
7. **قبل الفحص على null:** في `transferWalletValidation` (LeeTaxi) بيستخدم `$transferedUser->walletTransactions()` قبل ما يتأكد إنه موجود.
8. **الرصيد مش محجوز وقت طلب السحب:** الطلب `Pending` مش بيحجز فلوس، والتأكد بيحصل وقت القبول بس.
9. **ثغرات صغيرة في الكود:** أولوية العوامل في `$paidForPrevAmount = $paidAmount - $booking->total > 0 && ...` بتحط boolean في المتغير، ومفتاح ترجمة مكتوب `message.` بدل `messages.`.
10. **جداول قديمة غير مستخدمة** (`wallets` / `wallet_transactions` في Jawad) بتلخبط اللي يقرأ الكود.
11. **مفيش أي ربط بالدولة:** لا المحفظة ولا الحركات ولا طرق الدفع فيها `country_id` (الفصول 7 و9).
12. **مفيش فلاج "قابل للسحب" على الحركة:** الرصيد رقم واحد، فالفلوس المحوَّلة من مستخدم تانى تتسحب زي الفلوس المشحونة (الفصل 10 🔴).
13. **اختيار بوابة الدفع مكتوب يدوي في الـ Controller** (Jawad)، وبيانات `credentials` مخزّنة JSON عادي في الـ DB (LeeTaxi).
14. **`getCountryCodeByIp`** فيها مشاكل أداء وأمان وثبات (الفصل 8).

## 5. الحاجات الكويسة نعيد استخدامها
- الـ ledger كسجل غير قابل للتعديل (أي تصحيح = حركة جديدة أو عكس).
- `notes` كـ **مفتاح ترجمة + متغيرات** (مناسب جداً لـ ar/en في dorr).
- مرجع polymorphic (`model_type`/`model_id`) لربط الحركة بالحجز/الطلب.
- idempotency على `transaction_id` وخدمة مركزية لإكمال الدفع (Jawad).
- إيصال إجباري عند قبول السحب + سبب إجباري عند الرفض (Jawad).
- عكس العقوبة بدل حذفها (Jawad)، وإعدادات حدود قابلة للتعديل من الأدمن (الاتنين).
- Resource واحد يقرر اللون والأيقونة والنص لكل نوع حركة.


---

## 6. توجيه المشروع + الوضع الحالي في dorr

**العلاقة بين المشاريع (حسب توجيهك):**
- **dorr = LeeTaxi بس مبني من الصفر** بشكل نضيف ومتكامل وقابل للتوسع، فناخد من LeeTaxi *اتساع الفكرة* (أطراف كتير، تحويل، حدود، طرق دفع، سحب) ونتجنب أخطاءه.
- **Jawad = تطبيق شغال فعلاً في السوق** (سواق وراكب بس)، فناخد منه *الأفكار المجرّبة فعلياً*: خدمة إكمال الدفع المركزية بـ idempotency، إيصال السحب، عكس العقوبات، فصل بوابات الدفع في خدمات مستقلة.

**اللي موجود في dorr فعلاً (من الكود):**
- Laravel 12 + Modules (`Admin`, `User`, `Provider`, `AI`) و3 guards: `admin_api`, `user_api`, `provider_api`.
- `users` و `providers` جدولين منفصلين وكلاهما فيه `country_id`.
- `countries` فيها `currency_id` (كل دولة ليها عملة واحدة) و `is_default` و `status`. `currencies` فيها `decimal_places` و `exchange_rate` و `is_default`.
- `platform_settings` موجود (فيه `app_name` بس دلوقتي).
- **مفيش حجوزات/طلبات/فواتير ولا نظام صلاحيات لسه** (حسب [11-CHECKPOINT.md](11-CHECKPOINT.md)).
- النمط المعتمد: `Route → Controller → Service → Repository → Model` + Form Request + API Resource، ونصوص جديدة في `ar.json` و`en.json`، وتحديث `docs/` مع أي تغيير ([AI-INSTRUCTIONS.md](AI-INSTRUCTIONS.md)).

---

## 7. القاعدة الأولى: المحفظة مربوطة بالدولة (Country-scoped)

### البزنس (زي ما وصفته)
مستخدم فتح حساب من السعودية وشحن بالريال. بعد فترة سافر مصر: العملة مختلفة. رصيد وحركات السعودية **مينفعش يتصرفوا في مصر** (ممكن يظهروا بس مش قابلين للاستخدام هناك). عشان كده كل حركة لازم يكون معاها `country_id`.

### التصميم المقترح
- **محفظة لكل (مالك + دولة):** جدول `wallets` فيه `owner_type/owner_id` + `country_id` + `currency_id` (العملة بتيجي من `countries.currency_id` وقت إنشاء المحفظة ومبتتغيرش). القيد `unique(owner_type, owner_id, country_id)`.
- **كل حركة فيها `country_id` و `currency_id`** كمان (منسوخين من المحفظة) للتقارير والتدقيق، وعشان الحركة تفضل مفهومة حتى لو بيانات الدولة اتغيرت.
- **المحفظة بتتعمل عند أول شحن/استلام** في الدولة دي (مش لازم أول ما المستخدم يسجل).
- **الرصيد مبيتجمعش أبداً عبر عملات مختلفة.** الشاشة بتعرض محفظة لكل دولة؛ اللي بتخص الدولة الحالية متفعّلة، والباقي "معروض فقط".

### قاعدة الاستخدام (الأهم)
> الرصيد يُصرف بس على خدمة/طلب **دولته = دولة المحفظة**. مش بناءً على مكان المستخدم بالـ IP.

السبب: الـ IP بيتزوّر (VPN) والمسافر ممكن يكون IP بتاعه أي حاجة، لكن دولة الخدمة/الطلب معلومة قاطعة عند السيرفر. فالـ IP بيحدد **السياق الافتراضي** بس (أي دولة يفتح بيها التطبيق، وأي طرق دفع يشوفها) وميبقاش هو اللي بيسمح أو يمنع الصرف.

### قرارات معلّقة في النقطة دي
- لو المستخدم انتقل نهائياً لمصر: رصيده السعودي القابل للسحب (`withdrawable`) يسحبه ازاي؟ والـ `spend_only` (الفصل 10) اللي مالوش استخدام في مصر يعمل فيه إيه؟ (اقتراح: السحب متاح من أي مكان، و`spend_only` يفضل لحد ما يرجع، مع أداة دعم للأدمن.)
- هل فيه تحويل عملة تلقائي (`exchange_rate` موجود)؟ **الاقتراح: لأ في النسخة الأولى**، كل دولة مقفولة على عملتها.
- التحويل بين مستخدمين: لازم الاتنين في نفس الدولة (نفس عملة المحفظة)؟ (اقتراح: أيوه.)

---

## 8. تحديد الدولة — `getCountryCodeByIp` من LeeTaxi

### الدالة الحالية (`app/Helpers/generalHelper.php:314`)
```php
function getCountryCodeByIp(){
    $ip = request()->ip();
    $response = Http::get("http://www.geoplugin.net/json.gp?ip=$ip");
    $ipCountryCode = $response->json()['geoplugin_countryCode']?? 'SA';
    $countriesCodes = Country::select('alpha_code')->get()->pluck('alpha_code')->toArray();
    return in_array($ipCountryCode, $countriesCodes) ? $ipCountryCode :'SA';
}
```
**بتُستخدم في LeeTaxi في:** تسجيل الدخول/التسجيل بـ OTP (تحديد دولة رقم الهاتف)، و`GeneralController`، ومصادقة السواق. يعني لتخمين الدولة الافتراضية، مش للمحفظة.

### المشاكل فيها (نتجنبها في dorr)
1. **طلب HTTP متزامن في كل استدعاء** بدون caching، وممكن يتنادى أكتر من مرة في نفس الطلب (Form Request + Controller).
2. **مفيش timeout** (الافتراضي بتاع Laravel 30 ثانية) و**مفيش try/catch**: لو الخدمة وقعت `Http::get` بيرمي `ConnectionException` والطلب كله يقع.
3. **`http` مش `https`**، وخدمة مجانية بحدود استخدام ومن غير SLA.
4. **`request()->ip()` غلط خلف proxy/CDN** لو الـ trusted proxies مش مظبوطة، وعلى localhost (127.0.0.1) مفيش نتيجة.
5. **`'SA'` مكتوبة ثابتة** مرتين، بينما في dorr فيه `countries.is_default`.
6. بتسحب **كل الدول** من الـ DB في كل مرة، وبتستخدم `alpha_code` (في dorr العمود اسمه `code`).

### المقترح: `CountryResolver` (خدمة في `app/Services/General`)
ترتيب الأولوية:
1. **اختيار صريح** من المستخدم (header/param مثلاً `X-Country`).
2. **دولة البروفايل** (`users.country_id` / `providers.country_id`) لو مسجّل دخول.
3. **الـ IP** كـ hint: مزوّد قابل للتبديل من `config/geo.php` (هيدر CDN مثل `CF-IPCountry`، أو قاعدة GeoIP محلية، أو API بـ https)، مع **cache لكل IP** (مثلاً 24 ساعة)، و**timeout قصير (~2 ثانية)**، وأي فشل = نكمل للخطوة التالية بدون أخطاء.
4. **الدولة الافتراضية** من `countries.is_default` (مش ثابت في الكود).

النتيجة دايماً `Country` موجودة وفعّالة. وفيه override في `.env` للتطوير المحلي.

---

## 9. طرق الدفع (Payment Methods)

### اللي عندنا في المشروعين
- **LeeTaxi:** `sys_payment_methods` (صورة، `credentials` JSON جوه الـ DB، `is_online`، `status`، `service_type`، عنوان ووصف مترجم بـ 7 لغات) والمتسجل: Wallet / Online / Cash. البوابة (MyFatoorah) ورا `PaymentInterface` (`payOnline` / `successCallback` / `errorCallback`). فيه كمان `sys_service_payment_methods` + `sys_service_payments` لربط طريقة الدفع بخدمة. **مفيش ربط بالدولة.**
- **Jawad:** `payment_methods` (اسم مترجم + `status`) والمتسجل Cash / Credit Card / Wallet. البوابات خدمات منفصلة (`ARBPaymentService` بطاقة بنكية، `URPaymentService` محفظة موبايل بالتليفون، `TapPaymentService`) لكن **الاختيار بينهم مكتوب يدوي في الـ Controller** (`if payment_method == 'mobile_wallet'`). **مفيش ربط بالدولة برضه.**

### المطلوب في dorr
CRUD لطرق الدفع من الأدمن، وكل طريقة إما **مربوطة بدولة أو أكتر** أو **عامة (General)** تظهر مع كل الدول. عند شحن المحفظة:
> الطرق المتاحة = طرق **عامة** + طرق **مربوطة بدولة المحفظة/السياق**، وكلها فعّالة وتدعم الشحن.

مثال: مستخدم من السعودية يشوف الطرق العامة + اللي مخصوصة للسعودية.

### التصميم المقترح
- **`payment_methods`:** `code` (unique)، `gateway` (مفتاح يشاور على driver: myfatoorah / arb / ur / tap / manual…)، `type` (online / offline)، `is_global`، `supports_topup`، `status`، `sort_order`، الصورة (media)، `credentials` **بـ `encrypted:array` cast ومبتتعرضش في أي Resource**.
- **`payment_method_translations`:** الاسم والوصف (بنفس نمط الترجمة المعتمد في dorr).
- **`payment_method_country`** (pivot): `payment_method_id` + `country_id` + (اختياري) حدود/رسوم خاصة بالدولة دي.
- **قاعدة الإتاحة:** `status = active AND (is_global OR في pivot للدولة)`.
- **الطبقة الخلفية:** interface `PaymentGateway` (`initiate` / `handleCallback` / `verify` / `refund`) + driver لكل بوابة + registry بيختار الـ driver من `payment_methods.gateway` (بدل الـ `if` في الـ Controller).
- **`PaymentCompletionService` مركزية** بـ idempotency (نمط Jawad) هي الوحيدة اللي بتحوّل عملية دفع ناجحة لحركة محفظة، مع جدول `payment_transactions` (مبلغ، عملة، دولة، حالة، مرجع البوابة، logs للرد الخام).
- **التحقق على السيرفر:** الطريقة المختارة لازم تكون ضمن المتاحة لدولة المحفظة (منثقش في قائمة الموبايل)، والمبلغ ضمن حدود الدولة.
- **حدود الشحن حسب الدولة** (زي `min/max_amount_to_add` في Jawad لكن لكل دولة/عملة).

### مصدر الـ Integration (تأكيد من النقاش)
- **MyFatoorah** من LeeTaxi، و**URPay** و**بنك الراجحي (ARB)** من Jawad — نجيب منطق التكامل (التوقيع، التشفير، شكل الطلب/الرد) من الكودين، ونغلّفه في `PaymentGateway` driver مستقل لكل بوابة (فصل 9، البند "الطبقة الخلفية"). Tap (Jawad) مش مطلوبة دلوقتي، بس البنية بتستحمل إضافتها لاحقاً بنفس النمط.
- كل بوابة = كلاس مستقل بـ interface واحد (`initiate` / `handleCallback` / `verify` / `refund`)، عشان إضافة بوابة جديدة (لدولة جديدة) متلمسش كود بوابة تانية.

### الترجمة: لازم ديناميكية زي باقي dorr، مش أعمدة ثابتة
dorr نظامه **متعدد لغات ديناميكي** (جدول `languages` واللغات بتتضاف من الأدمن، والترجمات بعمود `locale` مفتوح زي `country_translations`). **نتجنب نمط LeeTaxi:** `sys_payment_methods` فيه عمود ثابت لكل لغة (`name`, `name_e`, `name_fr`, `name_hi`, `name_ms`, `name_ur`, `name_zh`) — لغة جديدة = migration جديدة. `payment_method_translations` (فصل 9) هتتبع **نفس نمط `country_translations`/`currency_translations`** الموجود في dorr بالفعل: صف لكل (`payment_method_id`, `locale`)، فإضافة لغة = بيانات بس من غير أي تعديل في الـ schema.

### أسئلة
- هل نسمح باستثناء دول من طريقة عامة؟ (اقتراح: لأ في النسخة الأولى.)
- هل فيه طرق دفع "يدوية" (تحويل بنكي بإيصال يعتمده الأدمن)؟
- **NEEDS-DECISION:** بيانات اعتماد MyFatoorah/URPay/ARB الفعلية (merchant id, secret keys...) هنجيبها منين؟ نفس حسابات LeeTaxi/Jawad ولا حسابات جديدة لـ dorr؟

---

## 10. 🔴 الخط الأحمر: فلاج "قابلية السحب" على كل ترانزاكشن

### الفكرة
أي فلوس في المحفظة إما:
- **`withdrawable` — قابلة للسحب:** فلوسك إنت (شحنتها بنفسك)، أو أرباحك كمقدم خدمة (سائق بعد رحلة).
- **`spend_only` — Hold (استخدام داخل التطبيق فقط):** فلوس جاتلك كتحويل من مستخدم تاني. **مينفعش تُسحب أبداً**، تتصرف على خدمات التطبيق بس.

**كل ترانزاكشن (إضافة أو خصم) لازم فيها الفلاج ده. مفيش ترانزاكشن من غير فلاج.**

> ملحوظة تسمية: ده مختلف عن `reserved` (حجز مؤقت لمبلغ طلب سحب لسه Pending). هنسميه `reserved` في الكود عشان منلخبطش.

### مثالك بالظبط (لازم يكون اختبار قبول)
| الخطوة | القابل للسحب | Hold (`spend_only`) | الإجمالي |
|---|---|---|---|
| أنا شحنت 1000 | **1000** | 0 | 1000 |
| حولت لـ B مبلغ 500 (رصيدي) | **500** | 0 | 500 |
| رصيد B بعد الاستلام | **0** | **500** | 500 |
| B حوّل الـ 500 ليا تاني | (B) 0 | (B) 0 | 0 |
| رصيدي بعد ما رجعوا | **500** | **500** | 1000 |

يعني اللي رجع ليا تحويل → `spend_only`، ومقدرش أسحب غير 500. (**قاعدة صارمة عن قصد**: الفلوس اللي عملت "رحلة" بين مستخدمين مترجعش قابلة للسحب.)

### الفلاج الافتراضي لكل نوع حركة (مسودة)
| العملية | الفلاج | ملاحظة |
|---|---|---|
| شحن بنفسي عبر بوابة الدفع | `withdrawable` | انظر "مخاطر" تحت (Chargeback) |
| أرباح مقدم الخدمة من الطلب (بعد العمولة) | `withdrawable` | |
| تحويل مستلَم من مستخدم | **`spend_only` دايماً** | مش parameter، ثابت في الـ Service |
| استرجاع (Refund) لمحفظتي | **نفس الفلاج بتاع الدفع الأصلي** | راجع القاعدة 4 |
| هدية شحن (نسبة سالبة) | **`spend_only` دايماً** | تفصيل كامل في الفصل 12 |
| مكافأة إحالة / نقاط / عروض | `spend_only` (مقترح) | **NEEDS-DECISION** لكل نوع |
| مكافأة/تعويض لمقدم الخدمة | `withdrawable` (مقترح) | **NEEDS-DECISION** |
| تسوية يدوية من الأدمن (إضافة) | الأدمن **يختار** الفلاج (إجباري) + سبب | |
| سحب | يخصم من `withdrawable` بس | |

### ترتيب الخصم عند الدفع (خدمة/تحويل/غرامة)
المقترح: **يخصم `spend_only` أولاً ثم `withdrawable`**، لأنه في صالح المستخدم (بيحافظ على اللي ممكن يسحبه) ومفيهوش باب تحايل: الفلوس المقيّدة بتتصرف داخل التطبيق فقط أصلاً. لو الخصم اتقسم على الفلاجين، يتكتب **صفّين (صف لكل فلاج)** بنفس `operation_id`. عشان كل صف = فلاج واحد بالظبط.
**NEEDS-DECISION:** الغرامات تخصم من أي فلاج الأول؟

### 🔴 الخطوط الحمراء (Invariants) — أي كسر ليها = bug حرج
1. **مفيش حركة بدون `bucket`:** عمود `NOT NULL` بدون default + قيد CHECK + enum cast. (الـ default الغائب متعمّد: المبرمج لازم يقرر.)
2. **الباب الوحيد لتغيير الرصيد هو `WalletService`.** ممنوع `Model::create` مباشر على الحركات أو المحافظ (يتفرض بـ guard في الموديل + اختبار معماري).
3. **السحب يقرأ من `withdrawable − reserved` فقط**، ومحجوز وقت الطلب (بيتحرر عند الرفض/الإلغاء).
4. **Refund بيرجع لنفس الفلاج:** كل صف خصم فيه `bucket`، وبيتعمل refund لكل صف بفلاجه. غير كده ممكن يتحول Hold → قابل للسحب بعملية "دفع ثم إلغاء" (ثغرة غسيل).
5. **`spend_only` الناتج عن تحويل مينفعش يتحول لـ `withdrawable` بأي مسار** ماعدا تسوية أدمن مسجّلة ومبررة.
6. **الحركات immutable:** مفيش update ولا delete ولا soft delete. التصحيح = **حركة عكسية** (`reverses_transaction_id`) بنفس الفلاج. (عكس Jawad اللي كان بيعكس العقوبة بـ soft delete.)
7. **كل عملية داخل `DB::transaction` + `lockForUpdate` على صف المحفظة** + `idempotency_key` فريد.
8. **معادلات لازم تفضل صح بعد كل عملية:** `withdrawable_balance = Σ(إضافات withdrawable − خصومات withdrawable)` وكذلك `spend_only`، ولا واحد فيهم سالب (إلا لو اتقرر دين المزوّد)، و`reserved ≤ withdrawable_balance`. مع أمر `wallet:reconcile` بيقارن المخزّن بالمحسوب.
9. **الفلاج ظاهر للأدمن والمستخدم:** كشف الحساب فيه (إجمالي / قابل للسحب / Hold) ولكل حركة علامة.
10. **اختبارات إلزامية** لكل صف في الجدول اللي فوق + المثال بتاعك + سباق متزامن.

### مخاطر لازم نقرر فيها
- **Chargeback / شحن ببطاقة مسروقة:** شحن → سحب فوري. اقتراح: فترة انتظار قبل ما الشحن يبقى `withdrawable`، أو السحب يرجع لنفس وسيلة الدفع فقط. (قاعدتك: الشحن قابل للسحب، ودي إضافة اختيارية للنقاش.)
- **تواطؤ:** A يحوّل لـ B (Hold)، وB "يشتري" خدمة وهمية من مزوّد متواطئ فتتحول لأرباح `withdrawable`. الحل مش بالفلاج لوحده: حدود سرعة، تأخير إتاحة أرباح المزوّد، مراجعة أدمن للأنماط المشبوهة.
- **قانونياً:** قاعدة "التحويل مينفعش يتسحب" غالباً لها علاقة بالتراخيص/مكافحة غسيل الأموال في كل دولة، فتتراجع مع مستشار قانوني قبل الإطلاق.
- **UX:** مستخدم حوّل بالغلط وصديقه رجّع → الفلوس عالقة `spend_only`. اقتراح أداة دعم للأدمن (تسوية مسجّلة)، من غير ما نغيّر القاعدة.


---

## 11. تخزين الرصيد: جدول محفظة + جدول حركات؟ ولا الجمع كل مرة؟

**الإجابة: الاتنين مع بعض (Hybrid).** جدول الحركات هو **المرجع الحقيقي** (ledger ثابت)، وجدول المحفظة **بيخزّن الأرصدة** وبيتحدّث في **نفس الـ DB transaction** بتاعة كل حركة. يعني اللي اقترحته (أخزّن القيم وأخصم/أجمع عليها كل مرة) صح، بس من غير ما نتخلى عن جدول الحركات.

### المقارنة
| | الجمع كل مرة (LeeTaxi / Jawad) | أرصدة مخزّنة فقط (من غير حركات) | **Hybrid (المقترح)** |
|---|---|---|---|
| قراءة الرصيد | `SUM` على كل الحركات كل مرة، وبتبطّأ مع الزمن | قراءة صف واحد | **قراءة صف واحد** |
| التزامن (سحبين في نفس اللحظة) | **مفيش صف نقفله**، فممكن يصرفوا نفس الرصيد | ممكن (`lockForUpdate` على صف المحفظة) | **ممكن** |
| التدقيق والنزاعات | ✅ | ❌ **مستحيل**: مفيش تاريخ نشرح بيه الرصيد | ✅ |
| إصلاح الخطأ | يتحسب من الحركات | مفيش مرجع | **نعيد بناء الأرصدة من الحركات** (`wallet:reconcile`) |
| قيود قاعدة البيانات (رصيد ≥ 0 مثلاً) | ❌ | ✅ | ✅ |
| فلترة بالرصيد داخل SQL (مين يقدر يستقبل طلبات) | صعب، لازم حلقة و`SUM` لكل مزوّد | ✅ | **✅ `WHERE balance >= x`** |
| الفلاجين (`withdrawable`/`spend_only`/`reserved`) | معقد | سهل | **سهل: عمود لكل واحد** |
| التعقيد | قليل | قليل | متوسط (عمليتين لازم يتنفذوا معاً) |

**ليه الجمع كل مرة غلط عندنا:** في Jawad دالة `notifyDriverIfDoesntHaveEnoughMoneyInWallet` بتحسب `wallet_amount` (يعني `SUM`) لكل سائق جوه أمر الإرسال، والرصيد بيتحسب في كل طلب API. والأهم: **مفيش حاجة نقفل عليها** وقت الخصم.

### قواعد الكتابة (Write Path) — مسار واحد فقط
داخل `WalletService` بس، بالترتيب:
1. `DB::transaction` ← قفل صف المحفظة `lockForUpdate` (في التحويل: قفل المحفظتين **بترتيب الـ id** عشان مفيش deadlock).
2. فحص `idempotency_key` (لو موجود يرجّع نتيجة العملية القديمة).
3. التحقق (الفلاج، الحدود، الرصيد المتاح، دولة المحفظة).
4. حساب الأرصدة الجديدة، وإدخال صفوف الحركات (كل صف = فلاج واحد + `balance_after`).
5. تحديث أرصدة المحفظة، والـ commit.
6. الإشعارات والأحداث `afterCommit` (مش جوه الـ transaction).

**ضمانات:** `CHECK` على الأعمدة (الأرصدة ≥ 0 إلا لو مسموح دين)، ومفيش كود بيكتب في `wallets` غير الخدمة، وأمر `wallet:reconcile` بيتجدول ويبعت تنبيه لو المخزّن ≠ المحسوب، واختبارات تزامن.

---

## 12. النسبة على عملية الشحن (رسوم/هدية) — حقل ديناميكي، Default = 0%

### البزنس (بالظبط زي ما اتقال)
- **الشحن بيتم على بوابة خارجية** (integration، هنحدد المصدر لاحقاً) وبيرجع بـ callback. **التطبيق ماخدش رسوم على الشحن نفسه افتراضياً — `percent = 0%` هو الـ Default.**
- الحقل بيفضل **موجود وديناميكي في الإعدادات** عشان مستقبلاً:
  - **حملة/عرض:** "اشحن بمبلغ كذا واحصل على 10% كاش باك/رصيد إضافي" → النسبة **سالبة**.
  - **احتياطي:** لو قررنا ناخد رسوم على الشحن مستقبلاً → النسبة **موجبة**.

### القاعدة الحسابية (بالظبط زي ما وصفتها)
> `percent` هي النسبة على **المبلغ اللي دفعه المستخدم فعلياً للبوابة** (سمّيه `paid_amount`).

- **`percent < 0` (هدية):** بينزل على المحفظة `paid_amount` **زائد** `|percent| × paid_amount`.
  > مثال: شحن 100 والنسبة **−10%** → المحفظة تاخد **100 + 10 = 110** (100 قابلة للسحب + 10 هدية Hold).
- **`percent > 0` (رسوم):** بينزل على المحفظة `paid_amount` **ناقص** `percent × paid_amount`.
  > مثال: شحن 100 والنسبة **1%** → المحفظة تاخد **100 − 1 = 99** (قابلة للسحب).
- **`percent = 0` (الافتراضي):** بينزل على المحفظة `paid_amount` بالكامل، قابل للسحب.

### الجدول المقترح: `wallet_fee_rules`
| العمود | المعنى |
|---|---|
| `operation` | `topup` بس دلوقتي (لاحقاً `transfer` / `withdrawal`، **NEEDS-DECISION** لو عمولة الخدمات هتكون هنا ولا في موديول الطلبات لاحقاً) |
| `country_id` (nullable) | قاعدة لدولة معينة، وnull = كل الدول |
| `payment_method_id` (nullable) | قاعدة لطريقة دفع معينة |
| `owner_type` (nullable) | user / provider / الاتنين |
| `percent` | **signed** decimal(8,4)، **default 0**. موجب = رسوم، **سالب = هدية** |
| `min_amount` / `max_amount` | حد أدنى/أقصى للرسوم أو الهدية لكل عملية (cap ضد الغلط) |
| `starts_at` / `ends_at` | مدة العرض (nullable = دايم) |
| `max_uses_per_owner` / `budget_total` | حدود العروض (عدد مرات لكل مستخدم، وميزانية العرض كلها) |
| `priority` / `status` | ترتيب وتفعيل |
| `created_by` / `updated_by` | من عدّل (تدقيق — القيمة دي بتأثر على فلوس حقيقية) |

**اختيار القاعدة:** الأكثر تحديداً بيكسب (دولة + طريقة دفع > دولة > عامة)، ولو تساوت، `priority`. **مفيش تجميع (stacking)** بين قواعد إلا لو اتقرر. **لو مفيش أي قاعدة مطابقة → `percent = 0`** (نفس الـ Default، مفيش استثناء).

### إزاي بتتسجل (خطوط حمراء 🔴)
- **حركتين منفصلتين بنفس `operation_id`:** (1) الشحن الأساسي `paid_amount` بفلاج `withdrawable`، (2) الرسوم/الهدية `|percent| × paid_amount` كحركة منفصلة (خصم لو رسوم، إضافة لو هدية) — **مش تعديل في مبلغ الشحن نفسه**. الكشف يبين السطرين بوضوح: "شحن 100" و"هدية ترويجية +10" أو "رسوم خدمة −1".
- 🔴 **الهدية (نسبة سالبة) `spend_only` دايماً، بدون استثناء.** لو كانت `withdrawable`: يشحن 100 ويسحب 110 فوراً = تسرب فلوس حقيقي. (مربوط بالفصل 10.)
- **لقطة القاعدة (snapshot):** وقت بدء الشحن بنحسب "عرض سعر" (`quote`) ونخزّن `fee_rule_id` والنسبة المطبّقة في `payment_transactions`. الـ callback (لما البوابة ترجع) **بيستخدم اللقطة المخزّنة وقت البدء، مش بيعيد حساب القاعدة** — لأن القاعدة ممكن تتغيّر أو العرض يخلص في الفترة بين بدء الدفع واستلام الـ callback. كل حركة برضه فيها `fee_rule_id` و`fee_percent` المطبّق فعلياً.
- **العرض للمستخدم قبل الدفع:** endpoint `quote` بيرجّع `paid_amount`، الهدية/الرسوم المتوقعة، والصافي اللي هيدخل المحفظة (مع توضيح إنه Hold لو هدية).
- **التقريب:** حسب `currencies.decimal_places` بقاعدة تقريب ثابتة ومكتوبة.

### أمثلة (تطبيق مباشر لكلامك)
| النسبة | شحن (`paid_amount`) | النازل على المحفظة | القابل للسحب | Hold (`spend_only`) |
|---|---|---|---|---|
| `0%` (Default) | 100 | 100 | **100** | 0 |
| `1%` (رسوم) | 100 | 99 | **99** | 0 |
| `−10%` (هدية/كاش باك) | 100 | 110 | **100** | **10** |

### السيناريو الكامل بتاعك (شحن + هدية + تحويل + استرجاع)
1. شحنت 100 والنسبة −10% → محفظتي: `withdrawable = 100`, `spend_only = 10` (إجمالي 110).
2. حوّلت الـ 100 (القابلة للسحب) لمستخدم B → محفظتي: `withdrawable = 0`. محفظة B: `spend_only += 100`.
3. B حوّل الـ 100 دي ليّا تاني → محفظتي: `spend_only += 100` (**مش withdrawable**، قاعدة الفصل 10). محفظتي بقت: `withdrawable = 0`, `spend_only = 110`. **القابل للسحب = صفر.**
4. **لو بدل الخطوة 2 عملت Refund للشحن قبل ما أحوّل أي حاجة** (لسه `withdrawable = 100` و`spend_only = 10` سليمين): الاسترجاع بيلغي **الحركتين مع بعض** بنفس `operation_id` → الـ 100 ترجع للبوابة، **والـ 10 هدية تتشال** لأنها مبنية على شحن اترجع. **(محسوم — مش NEEDS-DECISION تاني.)**

### قرارات معلّقة (NEEDS-DECISION)
1. لو المستخدم **صرف/حوّل جزء من الـ 100 أو من الـ 10** وبعدين طلب Refund للشحن: مينفعش نلغي حركة اتصرفت (مخالف لقاعدة الـ immutable في الفصل 10). المقترح: **الـ Refund مسموح بس لو الشحن الأصلي لسه كامل** (`withdrawable` و`spend_only` الناتجين عنه لسه موجودين زي ما هم)؛ غير كده الأدمن يعمل تسوية يدوية مسجّلة (دين أو خصم لاحق) بدل إلغاء تلقائي.
2. **حساب النظام اللي بيموّل الهدايا:** محفظة نظام لكل دولة (`owner_type = platform`, فصل 13) بتتسجل عليها الهدية كـ **مصروف** (`FinancialEntry` نوع expense)، والرسوم كـ **إيراد**. كل عملية شحن فيها هدية أو رسوم بتعمل صف في الحسابات المالية (فصل 13) موازي لحركة المحفظة.
3. سقف أقصى للهدية (`max_amount` وميزانية العرض `budget_total`) عشان مفيش نسبة سالبة كبيرة تتحط بالغلط من لوحة الأدمن. **validation صارم إجباري** على `percent` (مثلاً أقصى قيمة مطلقة مسموحة، وتأكيد مزدوج قبل الحفظ).
4. الرسوم الفعلية اللي بوابة الدفع نفسها بتاخدها (تكلفة حقيقية على التطبيق، منفصلة عن `wallet_fee_rules`) — تتسجل في الحسابات المالية (فصل 13) كمصروف تشغيلي؟

---

## 13. الحسابات المالية للنظام (إيرادات/مصروفات) — من Jawad

### الفكرة (اقتراحك)
Jawad فيه نظام محاسبة بسيط ومفيد: `FinancialCategory` + `FinancialEntry` — دفتر عام لكل حاجة **بتدخل أو تخرج من النظام نفسه** (مش من محفظة مستخدم): رسوم دخلت، كوبون اتخصم، مكافأة إحالة اتصرفت، ضرائب رحلة... إلخ. ده **منفصل عن `wallet_transactions`**: الأخيرة بتسجل حركة **محفظة مالك معيّن** (User/Provider)، والدفتر ده بيسجل **حركة النظام ككل** (إيراد/مصروف)، وبيتربط بيها بمرجع.

### التصميم في Jawad (وهو كويس كنقطة بداية)
- `financial_categories`: اسم مترجم، `type` (income/expense)، `is_system`، `status`.
- `financial_entries`: `category_id`, `type`, `amount`, `entry_date`, `description`, `notes` (JSON)، `reference_id`/`reference_type` (لربطها بالرحلة/العملية)، `admin_id` (لو دخلت يدوي).
- بتتسجل تلقائي من عدة خدمات: عمولة الرحلة (income)، ضرائب الرحلة (income)، تعويض خصم (expense)، مكافأة سواق (expense)، مكافأة إحالة (expense).
- شاشة أدمن (`FinanceController`) بتعرض القائمة + ملخص (إجمالي دخل/مصروف/صافي) بفلاتر تاريخ ونوع وفئة.

### مشاكل في تنفيذ Jawad (نتجنبها في dorr)
1. **البحث عن الفئة بالاسم المترجم:** `FinancialCategory::where('name->en', 'Referral Expenses')->first()` — لو حد غيّر الاسم من الأدمن، الكود يبوظ بصمت (`null` بدل exception).
2. **`category_id => 1` كـ fallback مكتوب صريح في الكود** لو الفئة مش موجودة — بيانات بتتسجل في فئة غلط بدون تنبيه.
3. **`reference_type` نص حر** (`'Travel'`, `'Travel Tax'`) مش اسم كلاس حقيقي → مورف مكسور، مينفعش تعمل `reference()->first()` بشكل موثوق.
4. **إنشاء الحركات متكرر ومباشر** (`FinancialEntry::create(...)`) في أكتر من Service من غير باب موحّد.

### المقترح لـ dorr
- **`financial_categories`:** زي Jawad، بس مع **`slug` ثابت غير قابل للتغيير** (مش بالاسم المترجم) هو اللي بيتستخدم في الكود، والاسم المترجم للعرض بس. فئات النظام الأساسية (`topup_fee`, `promo_bonus_cost`, `withdrawal_fee`, `referral_reward`, ...) بتتعمل بـ seeder وتتقفل بـ `is_system = true` (مينفعش تتمسح).
- **`financial_entries`:** نفس أعمدة Jawad، لكن `reference_type` بـ **morph map حقيقي** (مش نص حر)، وربط اختياري بـ `wallet_transaction_id` لما يكون فيه حركة محفظة مقابلة (زي رسوم/هدية الشحن، فصل 12).
- **باب واحد:** `FinancialLedgerService::record(categorySlug, type, amount, reference, notes)` — مفيش `FinancialEntry::create()` مباشر في أي Service، ومفيش fallback صامت: لو الـ slug مش موجود يرمي exception واضح وقت التطوير.
- **الربط بالمحفظة:** كل حركة `wallet_fee_rules` (رسوم أو هدية) بتولّد صف موازي هنا تلقائياً من جوه `WalletService` نفسه (مش من كود منفصل ممكن يتنسى).
- **`country_id`** على الصف (زي باقي الجداول) عشان تقارير الإيرادات/المصروفات تتقسم بالدولة.

### أسئلة
- تاكسونومي الفئات النهائي (إيه الفئات المطلوبة من أول يوم غير رسوم/هدايا الشحن)؟
- هل الدفتر ده بديل تام لتقارير مالية مستقبلية، ولا هيتوسّع لاحقاً لمحاسبة كاملة (قيد مزدوج حقيقي)؟

---

## 14. الرصيد المسموح لاستقبال الطلبات (حد الدين)

### اللي في المشروعين
- **LeeTaxi:** `driver_settings.minimum_amount_to_receive_request` (الافتراضي 100، التعليق "الحد الأدنى لمديونيات السائق"). لو مديونية السائق (`رصيد × −1`) وصلت الحد يوصله إشعار (تحديث حالة السائق متعلّق ومقفول بتعليق في الكود). وكمان بيحدد أقصى مبلغ يقدر السائق يشحنه لمحفظة العميل من كاش: `المسموح = الحد + الرصيد`.
- **Jawad:** `wallet_settings.driver_min_to_get_ride` بيتستخدم **في أمر إرسال الرحلات** لاستبعاد السائق المديون، وفي إشعاره، ونفس معادلة `المسموح`. و`max_debt_for_cash` **للراكب**: لو رصيده أقل من `−الحد` مينفعش يحجز رحلة كاش.

### مشاكل لازم نتجنبها
1. إعداد **واحد عام** (`::first()`) مفيش دولة ولا نوع خدمة.
2. **قيم افتراضية مكتوبة في الكود ومختلفة** (25 / 50 / 0) حسب المكان، فلو الإعداد اتمسح السلوك يتغيّر بصمت.
3. **المنطق مكرر في 3–4 أماكن.**
4. التسمية مضلّلة (`min_to_get_ride` هو في الحقيقة **أقصى دين**).
5. الفحص وقت الإرسال بس، مش وقت **قبول** الطلب (سباق: يقبل والدين اتغيّر).
6. `SUM` لكل سائق داخل حلقة.

### المقترح لـ dorr
- **إعدادات لكل دولة** (واختيارياً لكل تصنيف خدمة من `service_categories`): `min_allowed_balance` **signed** (مثلاً `-100` = مسموح دين لحد 100، `0` = لازم رصيد صفر أو أكتر)، لمقدم الخدمة (استقبال الطلبات) وللمستخدم (دفع كاش). يعني قيمة واحدة بتغطي "الحد الأدنى للرصيد" و"أقصى دين" اللي كانوا في Jawad كإعدادين بأسماء مضلّلة.
- **خدمة واحدة** `WalletEligibilityService::canReceiveRequests(provider, country, category)` بتتنادى في: الإرسال (فلترة SQL على الرصيد المخزّن، مفيش حلقة)، **وقت القبول** (إعادة فحص داخل نفس القفل)، وواجهة المقدّم بتقوله ليه موقوف وكام يشحن.
- **الرصيد المحسوب هو رصيد محفظة دولة الخدمة** (دين السعودية مبيوقفش طلبات مصر).
- **مفيش قيمة افتراضية صامتة:** الإعداد بيتعمل تلقائياً عند تفعيل الدولة، ولو ناقص = **الفحص يفشل بأمان (fail closed)** برسالة واضحة.
- **الدين والفلاجين:** الرصيد يبقى سالب في `withdrawable` بس (والـ `spend_only` عمره ما يبقى سالب)، والاستقبال/السماح بالطلب يتحسب على **الإجمالي**. السحب مسموح فقط لو `withdrawable − reserved > 0`.

### الدين لأي الطرفين؟ (محسوم من كلامك)
مسموح **للاتنين (User و Provider)**، مش للمزوّد بس:
- **Provider:** دين تشغيلي طبيعي (عمولة/ضرائب مخصومة أول بأول، زي LeeTaxi/Jawad) — بيتحكم فيه `min_allowed_balance` عشان يوقف استقبال طلبات جديدة لحد ما يشحن.
- **User:** الدين هنا مش تشغيلي، ده **نتيجة عقوبة** (إلغاء متكرر أو مخالفة) بتنزّل حركة `Penalty` (`withdrawable`) والرصيد يبقى سالب. نفس `min_allowed_balance` (بعلامة سالبة) بيمنعه من طلب خدمة جديدة **لحد ما يشحن**، من غير ما يمنعه من استخدام التطبيق بشكل عام (تصفح، تعديل بيانات...).
- **خدمة واحدة** `WalletEligibilityService::canRequestService(owner, country, category)` بتتنادى على الاتنين (Provider وقت استقبال طلب، User وقت إنشاء طلب/حجز جديد)، بنفس منطق `min_allowed_balance` لكل دولة.

---

## 15. الاتجاه المقترح (مسودة للنقاش، مش قرار)

### المالك: Morph لـ User و Provider
- `owner_type` / `owner_id` (polymorphic) للمحفظة، بأسماء alias ثابتة (`user`, `provider`, `platform`) بدل تخزين أسماء الكلاسات في الـ DB — لكن عن طريق mapping محلي جوه موديول Wallet (`OwnerType`)، **مش** `Relation::morphMap()`/`enforceMorphMap()` العالمية (دي كسرت علاقة polymorphic تانية غير متعلقة بالمحفظة وقت التنفيذ الفعلي — التفاصيل في `wallet-structure.md § 0`).
- trait `HasWallets` على `User` و`Provider` (علاقة `wallets()` + `walletFor(Country)`) **من غير أي منطق مالي جواهم**؛ كل المنطق في `WalletService`.
- الإعدادات والقواعد ممكن تتحدد حسب `owner_type`.

### الجداول
> **التفاصيل الدقيقة (الأعمدة النهائية، minor units، composite FKs، `wallet_holds`...) موجودة في [wallet-structure.md](wallet-structure.md) — هنا مجرد سكتش عالي المستوى.**
- **`wallets`:** `owner_type`, `owner_id`, `country_id`, `currency_id`, `withdrawable_minor`, `spend_only_minor`, `held_withdrawable_minor`, `held_spend_only_minor`, `status`, `unique(owner_type, owner_id, country_id)`.
- **`wallet_transactions`** (immutable): `uuid`, `wallet_id`, `operation_id`, `direction`, **`bucket`**, `type` (بما فيها `fee` و`promo_bonus`)، `amount_minor` (موجب)، `currency_id`, `country_id`, `balance_after_minor` و`total_balance_after_minor`, مرجع polymorphic، `counterparty_wallet_id`, `reverses_transaction_id`, `fee_rule_id`, `fee_percent`, `idempotency_key` + `request_hash` (unique)، `notes` (مفتاح ترجمة + متغيرات)، `created_by`.
- **`wallet_holds`** (Hold/Capture): حجز مبلغ من غير حركة مالية فعلية، لحد ما يتحصّل (capture) بالمبلغ الفعلي أو يتحرر (release). موحّد مع حجز طلبات السحب.
- **`wallet_settings` لكل دولة:** حدود الشحن، حدود السحب، حدود التحويل (لكل عملية/يوم/شهر)، `min_allowed_balance` (فصل 14).
- **`wallet_fee_rules`** (فصل 12، `operation = topup` بس بالبداية، default `percent = 0`).
- **`financial_categories` + `financial_entries`** (فصل 13، دفتر إيرادات/مصروفات النظام، منفصل عن حركات المحافظ).
- **`withdrawal_methods` + `withdrawal_requests`:** بنك/محفظة موبايل، إيصال إجباري عند القبول (media)، سبب عند الرفض، ومربوطة بـ `reserved`.
- **`payment_methods` + translations (نمط `country_translations` الديناميكي) + `payment_method_country` + `payment_transactions`** (فصل 9، والأخيرة فيها لقطة `quote`).
- **المبالغ:** `decimal` (مش `float`)، **NEEDS-DECISION** decimal(18,4) ولا أعداد صحيحة (minor units) بحسب `currencies.decimal_places`.

### الطبقات
`Route → Controller → Service → Repository → Model`: `WalletService`, `FeeService` (اختيار القاعدة + `quote`), `WalletEligibilityService` (فصل 14)، `FinancialLedgerService` (فصل 13)، `WithdrawalService`, `PaymentCompletionService`, `CountryResolver`, `PaymentGatewayRegistry`. مع Form Requests وAPI Resources وصلاحيات.

### المراحل المقترحة
1. اعتماد القرارات (الملف ده + ADRs).
2. **النواة:** `wallets` + `wallet_transactions` + `WalletService` + الفلاج + الخطوط الحمراء + اختبارات (مثالك، التزامن، `reconcile`).
3. `CountryResolver` + إعدادات الدول.
4. `financial_categories` + `financial_entries` + `FinancialLedgerService` (تأسيس مبكر عشان كل حاجة تتسجل فيه من البداية).
5. طرق الدفع (CRUD أدمن + ربط الدول + integration مع MyFatoorah/URPay/ARB) + بوابة واحدة كبداية.
6. الشحن الأونلاين + `PaymentCompletionService` + `quote`.
7. `wallet_fee_rules` (رسوم/هدية الشحن) مع لقطة القاعدة + ربطها بالحسابات المالية.
8. كشف الحساب في User/Provider + شاشات الأدمن.
9. السحب (Provider) بإيصال.
10. `WalletEligibilityService` (حد الدين لاستقبال/طلب الخدمات) عند ما نبني الطلبات، لـ Provider وUser مع بعض.
11. تحويل بين المستخدمين + الحدود.
12. الدفع من المحفظة (بعد ما تتعمل الطلبات/الحجوزات).
13. الاختياري: عقوبات/مكافآت/إحالة.

---

## 16. النقاش: إيه الصح وإيه اللي محتاج تفكير

**محسوم (من كلامك في التحديثين):**
- المحفظة مرتبطة بالدولة، وطرق الدفع مرتبطة بدول أو عامة.
- فلاج قابلية السحب على كل حركة، والتحويل المستلَم = `spend_only` دايماً.
- **المالك Morph لـ User و Provider.**
- **تخزين الأرصدة في جدول المحفظة + جدول حركات** (Hybrid، فصل 11).
- نسبة الشحن ممكن تبقى سالبة (هدية) والـ default **0%** (فصل 12)، والهدية `spend_only` دايماً.
- **الاسترجاع بيلغي الهدية معاه** طالما الشحن الأصلي لسه كامل (فصل 12).
- **الدين مسموح للاتنين** (Provider تشغيلي، User عن طريق عقوبات) وبيمنع طلب خدمة جديدة بس، مش استخدام التطبيق (فصل 14).
- **بنجيب الـ integration من MyFatoorah (LeeTaxi) وURPay/ARB (Jawad)**، وطرق الدفع بترجمة ديناميكية زي باقي dorr (فصل 9).
- **دفتر إيرادات/مصروفات النظام** (`financial_categories`/`financial_entries`) مأخوذ من Jawad بتحسينات (فصل 13).

**نقاط عايز رأيك فيها (بالأولوية):**
1. **الصرف بحسب دولة الخدمة مش الـ IP** (فصل 7). موافق؟
2. **الرسوم شاملة ولا فوق المبلغ لو فعّلناها مستقبلاً؟** (مش مطلوب دلوقتي، بس التصميم محتاج يحسمها قبل التنفيذ.)
3. **ترتيب الخصم:** `spend_only` الأول؟ (مقترحي: أيوه.)
4. **مكافآت/إحالة/تعويضات (خارج هدية الشحن):** `spend_only` ولا `withdrawable`؟ لكل نوع.
5. **الشحن `withdrawable` فوراً** ولا بفترة انتظار/سحب لنفس الوسيلة (مخاطرة Chargeback)؟
6. **حد الدين (`min_allowed_balance`)** لكل دولة فقط ولا لكل تصنيف خدمة كمان؟
7. **بيانات اعتماد البوابات** (merchant id / secret keys) لـ MyFatoorah/URPay/ARB — حسابات LeeTaxi/Jawad ولا حسابات جديدة؟
8. **Escrow** وعمولة المنصة على أرباح المزوّد (هل بتتحط في `wallet_fee_rules` بـ `operation` جديد لاحقاً؟).
9. **تاكسونومي `financial_categories`** النهائي (فصل 13).
10. **مكان الكود:** موديول `Wallet` جديد ولا `app/` (General)؟
11. **الصلاحيات:** مفيش permissions لسه في dorr، ننتظره ولا نبدأ بحماية مؤقتة على الـ guard؟
12. **decimal vs minor units** للمبالغ.

---

## 17. أفكار إضافية (هتتضاف هنا)
<!-- سيب المكان ده لأفكارك الجاية، وبعد ما تتجمع نحدّث الأقسام 7–16. -->

-
