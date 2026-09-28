# تقرير وحدة SMS الشامل

**التاريخ:** 2026-09-28 — **المجرى:** dorr  
**المكان:** `Modules/Sms/` (Backend) + `resources/js/modules/admin/themes/theme-1/views/sms-*/` (Frontend)

---

## 1. الملخص

وحدة SMS داخل الـ Admin SPA توفّر:
- **Providers**: تسجيل مزوّدات الرسائل (Twilio + SMS Misr فقط) مع تكوين اختياري لكل مزوّد (مشفّر) وزر Test قبل الحفظ.
- **Accounts**: حسابات SMS فعليّة تحمل بيانات الاعتماد (مشفّرة) وهي **مصدر الحقيقة الوحيد** أثناء الإرسال.
- إرسال رسالة اختبارية (Test SMS) مع تطبيع الرقم إلى E.164 حسب الدولة المختارة، وفحص الرصيد، واختبار الاتصال.

**البنية العامة:**
```
Route → Controller → Service → Repository/Model
         Form Request          API Resource
```

---

## 2. جداول قاعدة البيانات (Migrations)

### 2.1 `sms_providers` — المزوّدات
`Modules/Sms/database/migrations/2026_09_28_090000_create_sms_providers_table.php`

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | اسم المزوّد |
| `key` | string **unique** | `twilio` أو `sms_misr` فقط |
| `configuration` | text nullable | تكوين المزوّد (مشفّر `encrypted:array`) — اختياري |
| `is_active` | boolean (افتراضي true) | |
| `is_available` | boolean (افتراضي true) | |
| `last_tested_at` | timestamp nullable | |
| `test_status` | string (افتراضي `never_tested`) | |
| `test_error` | string nullable | |
| `created_at` / `updated_at` | timestamps | |

### 2.2 `sms_accounts` — الحسابات
`Modules/Sms/database/migrations/2026_09_28_090010_create_sms_accounts_table.php`

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | اسم الحساب |
| `provider_id` | FK → `sms_providers.id` (cascade delete) | |
| `sender` / `sender_code` / `sender_type` | string nullable | |
| `configuration` | text nullable | بيانات الاعتماد الفعلية **مشفّرة** `encrypted:array` |
| `purpose` | string nullable | |
| `is_default` | boolean (فهرس) | حساب افتراضي واحد فقط (إجبار في الخدمة) |
| `is_active` | boolean (افتراضي true) | |
| `last_tested_at` | timestamp nullable | |
| `test_status` | string (فهرس، افتراضي `never_tested`) | |
| `test_error` | string nullable | |
| `settings` | json nullable | |
| `created_at` / `updated_at` | timestamps | |

---

## 3. الـ Models

### `Modules/Sms/App/Models/SmsProvider.php`
- `$fillable`: `name, key, configuration, is_active, is_available, last_tested_at, test_status, test_error`
- Casts: `configuration => encrypted:array` (تشفير عند الكتابة/فك عند القراءة — لا نص عادي على القرص)
- `getConfigurationPlaintextAttribute()`: الاسم المستخدم داخل الـ backend فقط (آمن من التسريب للـ API)
- `smsAccounts(): HasMany`
- `providerLabel()`, `capabilities()`, `supports()` عبر `SmsAdapterRegistry`
- يستخدم `SearchFilterTrait` للبحث

### `Modules/Sms/App/Models/SmsAccount.php`
- `$fillable`: شامل كل الأعمدة عدا `provider` (علاقة)
- `$hidden`: **`configuration`** — ممنوع تسريب بيانات الاعتماد عبر serialization عادي
- Casts: `configuration => encrypted:array`, `settings => array`, booleans + datetime
- `provider(): BelongsTo`
- `getConfigurationPlaintextAttribute()`
- `isUsable()` → `SmsAvailabilityService::accountReady()`

---

## 4. الـ Backend (Modules/Sms)

### 4.1 الراوات — `Modules/Sms/routes/admin.php` (guard: `admin_api`)
**Providers (`/api/admin/v1/sms-providers`):**
| Method | URI | Action |
|---|---|---|
| GET | `/sms-providers` | index |
| POST | `/sms-providers` | store |
| GET | `/sms-providers/{id}` | show |
| PUT/PATCH | `/sms-providers/{id}` | update |
| DELETE | `/sms-providers/{id}` | destroy |
| POST | `/sms-providers/delete-multiple` | deleteMultiple |
| PATCH | `/sms-providers/{id}/status` | toggleActive |
| GET | `/sms-providers/dropdown` | dropdown |
| GET | `/sms-providers/types` | types (schema/capabilities من الـ registry) |
| POST | `/sms-providers/{id}/test` | readiness (لا يوجد اتصال حي) |
| **POST** | **`/sms-providers/test-draft`** | اختبار حي لتكوين لم يُحفظ |

**Accounts (`/api/admin/v1/sms-accounts`):**
| Method | URI | Action |
|---|---|---|
| GET | `/sms-accounts` | index |
| POST | `/sms-accounts` | store |
| GET | `/sms-accounts/{id}` | show |
| PUT/PATCH | `/sms-accounts/{id}` | update |
| DELETE | `/sms-accounts/{id}` | destroy |
| GET | `/sms-accounts/dropdown` | dropdown (حسابات قابلة للاستخدام) |
| GET | `/sms-accounts/providers-dropdown` | المزوّدات الفعّالة |
| POST | `/sms-accounts/test-draft` | اختبار بيانات لم تُحفظ |
| POST | `/sms-accounts/send-test` | إرسال رسالة اختبارية (يستخدم حزمة `send-test`) |
| POST | `/sms-accounts/{id}/test` | live connection test |
| GET | `/sms-accounts/{id}/balance` | الرصيد (حسب القدرة) |
| POST | `/sms-accounts/{id}/set-default` | تعيين كافتراضي |
| POST | `/sms-accounts/delete-multiple` | |
| PATCH | `/sms-accounts/{id}/status` | |

**ملاحظة:** لا `SubstituteBindings` — الـ controllers تستقبل id خام والـ service يحلها بـ `findOrFail()`.

### 4.2 الـ خدمات — `Modules/Sms/App/Services/Sms/`
- **`SmsAdapterRegistry`** — سجل المزوّدين الوحيد (المفاتيح: `twilio`, `sms_misr`). يوفّر: `keys()`, `registry()`, `adapter()`, `label()`, `configurationSchema()`, `capabilities()`, `prepareConfiguration()` (تطبيع + حفظ الأسرار عند التعديل)، `validateConfiguration()`، `testConnection()`, `getBalance()`, `getSenderIds()`.
- **`SmsProviderService`** — إدارة المزوّدين: create/update مع `prepareConfiguration` و`assertConfigurationComplete`، readiness، `dropdown()`, `types()`، و**`testDraft(key, config, providerId?)`** يدمج الأسرار المخزّنة عند التعديل ثم اتصال حي بالـ adapter.
- **`SmsAccountService`** — إدارة الحسابات: إنشاء/تعديل مع تحقق schema المزوّد المختار + حفظ الأسرار، `clearOtherDefaults()` (حساب افتراضي واحد)، `testConnection()` يحدّث `test_status`، `testDraft()`, `balance()` (غير متاح لـ SMS Misr)، `sendTest()` مع تحذير عند غياب sandbox.
- **`SmsService`** — بوابة الإرسال: `send()` + `sendTestMessage()` — يتحقق `assertUsable` (حساب فعّال + اختبار ناجح + مزوّد فعّال)، يطبّع الرقم حسب الدولة، يقدّر الأجزاء، يدعم `test_only`.
- **`SmsAvailabilityService`** — حكم واحد لجاهزية SMS: يتوفر فقط عند وجود حساب نشط + مزوّد نشط + `test_status = passed`.
- **`SmsMessageHelper`** — عدد الأجزاء (GSM-7: 160/153 · UCS-2: 70/67) وقابل للتخصيص حسب الـ provider.
- **`PhoneNumberNormalizer`** — تحويل رقم محلي إلى E.164 عبر `Country::dial_code/phone_starts_with/phone_length`.

### 4.3 الـ Adapters — `Services/Sms/Adapters/`
- **`BaseSmsAdapter`** — قاعدة: `stripSensitive()` (تنظيف الأسرار من رسائل الخطأ)، `isTestModeEnabled()`, `normalizeHttpError()`.
- **`TwilioSmsAdapter`** — schema: `account_sid` (secret), `auth_token` (secret), `from`, `test_mode` bool, `test_phone_number`. Capabilities: send_sms, send_bulk, balance, test_mode, delivery_reports, sender_ids. API: `Balance.json` + `Messages.json` (Basic Auth).
- **`SmsMisrSmsAdapter`** — schema: `username` (secret), `password` (secret), `sender`, `environment` (select live/test). Capabilities: send_sms, send_bulk, test_mode, sender_approval (**لا balance**). API: `https://smsmisr.com/api/SMS/` مع خريطة أكواد `1901-1912`.
- **(حُذفت):** Vonage, Infobip, Bird/MessageBird, SMS Smart Egypt, MoceanAPI — أُزيلت من الـ registry وملفاتها محذوفة (بناءً على طلب المستخدم: Twilio + SMS Misr فقط).

### 4.4 Requests
- **`SmsProviderRequest`** — validation: `name` required، `key` ضمن `SmsAdapterRegistry::keys()` + `unique:sms_providers,key` (ignore على update)، `configuration` nullable array.
- **`SmsAccountRequest`** — `provider_id` مطلوب عند الإنشاء فقط؛ `sender_type` في [`number`,`alphanumeric`]؛ `configuration.*` nullable.
- **`SmsSendTestRequest`** — `account_id` nullable، `country_id` required (exists)، `to` required max32، `message` nullable max1600.

### 4.5 Resources (API Resource — لا تسريب للأسرار)
- **`SmsProviderResource`** — يعرض:
  - `id, name, key, label, is_active, is_available, test_status, test_error, last_tested_at`
  - `capabilities[]`
  - **`configuration_meta.fields[]`**: `{key, label, type, required, secret, options, value (secret ⇒ null), has_value, is_set}`
  - `accounts_count` (whenCounted), `created_at/updated_at`
- **`SmsAccountResource`** — مثل السابق + `provider`, `sender*`, `purpose`, `is_default`, `is_usable`, `provider_is_active`, `supports_balance`, `supports_sandbox`, `settings`.

### 4.6 Permissions (Seeder `AdminPermissionSeeder`)
- مجموعتان: `sms-providers`, `sms-accounts` (module_name `general_services`) — actions: `view, create, update, delete, change-status, multiple-delete, test`.
- الـ middleware الخرائط: providers: `test → [test, testDraft]` · accounts: `test → [test, testDraft, sendTest, balance]`, `update → [update, setDefault]`, `view → [index, show, dropdown, providersDropdown]`.

### 4.7 Config — `Modules/Sms/config/config.php`
- `log_channel`, `e164_providers` (`['twilio']`)، `default_sender_type`, `test.prefer_sandbox = true`, `test.require_live_warning = true`, `enabled`.

### 4.8 Translations — `lang/en/sms.php` + `lang/ar/sms.php`
- `providers.*`: created/fetched/… + `connection_successful`, `connection_failed`, `missing_configuration`, `test_errors.sms_misr.*` و`test_errors.required_credentials`.
- `accounts.*`: رسائل CRUD + `connection_successful`, `balance_*`, `test_*`, `country_*`, `live_test_sent` …

---

## 5. الـ Frontend

### 5.1 الملفات
| الملف | الدور |
|---|---|
| `views/sms-providers/index.vue` (516 سطر) | قائمة المزوّدين: بحث، فلاتر active/inactive، جدول (اسم/متاح/عدد الحسابات/حالة الاختبار/الحالة/التاريخ)، select-all، pagination، أزرار test/edit/delete، ConfirmDeleteModal |
| `views/sms-providers/ModalCreateAndUpdate.vue` (564 سطر) | مودال add/edit مع **زر Test** في الـ footer |
| `views/sms-accounts/index.vue` (560 سطر) | قائمة الحسابات + balance + set-default + stethoscope test |
| `views/sms-accounts/ModalCreateAndUpdate.vue` (587 سطر) | مودال add/edit الحسابات |
| `composables/useSmsProviders.js` | CRUD (عبر `crudStructure`) + toggle active + `testProvider` |
| `composables/useSmsAccounts.js` | CRUD + toggle + setDefault + testConnection + fetchBalance |
| `stores/smsProviders.js` / `stores/smsAccounts.js` | عدّادات (total/active/inactive) |
| `composables/useSmsConfirmDelete.js` | تأكيد حذف موحّد |
| `components/ui/TestStatusBadge.vue` | شارة حالة الاختبار (passed/failed/never_tested) |

### 5.2 الـ routing والـ Sidebar
- `modules/admin/routes.js`: `admin.sms.providers.index` (permission `sms-providers.view`) + `admin.sms.accounts.index` (permission `sms-accounts.view`).
- `components/layout/admin/Sidebar.vue`: قسم SMS فيه رابطا Providers و Accounts (بعد إصلاح الـ dropdown).

### 5.3 مودال المزوّد (الميزة الأحدث)
- Select نوع المزوّد (فلاتر) → جلب `types` من `/sms-providers/types`.
- حقول config ديناميكية حسب الـ schema: نص / كلمة مرور / select / toggle (boolean) + شارة "configured" للأسرار المحفوظة.
- **Vuelidate validation** على حقول الـ config: `required` لحقول الـ schema، تُتجاوز الـ secrets عند التعديل إن كانت محفوظة (`is_set`)، `is-invalid` + `.invalid-feedback`، `$touch` عند التغيير، reset عند تبديل المزوّد/التعبئة، مع الحفاظ على نفس الـ reactive object لسلامة الربط.
- **زر Test** في الـ footer → `POST /sms-providers/test-draft` — يمنع الإرسال إذا كان الـ config غير مكتمل (validates عبر vuelidate).
- الأسرار تُعرض `type=password` حتى لو كانت `type:text` في الـ schema.

### 5.4 i18n — `resources/js/locales/en.json` + `ar.json`
- مفتاح `sms` يحوي `providers.*` (title, test, testing, test_success, test_failed, select_option, schema_hint…) و`accounts.*` و`fields.*` (title, optional, configured).

---

## 6. تدفقات الحماية (Security)

1. **التشفير عند الراحة:** `SmsAccount.configuration` و`SmsProvider.configuration` كلاهما `encrypted:array` — لا نص عادي في DB.
2. **لا تسريب عبر API:** `$hidden` + كل Resources تستخدم `configuration_meta` الذي يعرض `is_set`/`has_value` والأسرار `value: null` دائماً.
3. **تنظيف الأسرار من أخطاء المزوّدين:** `stripSensitive()` في `BaseSmsAdapter::normalizeError()`.
4. **إبطال الاختبار عند تعديل بيانات الحساب:** أي update للـ configuration يعيد `test_status = never_tested`.
5. **قفل الحذف:** لا يمكن حذف مزوّد له حسابات (`sms_providers.in_use` 400).
6. **اظهار القيم غير السرية فقط** في edit لإعادة العرض دون قراءة السر.

---

## 7. ما تم إنجازه في هذه الجلسة الأخيرة

1. **تكوين + زر Test في مودال المزوّد**:
   - Backend: حفظ `configuration` على `sms_providers` (مشفّر) في create/update مع `prepareConfiguration`، إضافة endpoint `POST /sms-providers/test-draft` + صلاحية `test`، إضافة مفاتيح lang جديدة.
   - Frontend: حقول ديناميكية + زر Test + تعديل `configuration_meta` للقالب.
2. **Vuelidate validation** على حقول الـ configuration (الطلب الأخير) — ربطة مع `useValidation.requiredField`، حالات invalid، رسائل الخطأ، touch/reset/clear.
3. **اقتصار المزوّدين على Twilio + SMS Misr** (الطلب الأخير):
   - حذف 5 مفاتيح من `SmsAdapterRegistry` + حذف ملفات الـ adapters (Vonage, Infobip, Bird, SMSEG, Mocean).
   - `e164_providers` → `['twilio']`.
   - إزالة ترجمة `test_errors.smseg` (en/ar).
   - إصلاح الاختبارات (unit + feature) لتستخدم twilio/sms_misr فقط + `types` count 2.
   - تحديث `docs/modules/sms/README.md` + `docs/11-CHECKPOINT.md`.

---

## 8. الاختبارات (Tests)

| الملف | النطاق |
|---|---|
| `tests/Unit/SmsAdaptersTest.php` | registry/schema/capabilities لكل مزوّد، رفض بيانات فارغة بدون شبكة، validate/prepare configuration، إخفاء الأسرار من normalizeError، أكواد SMS Misr، حساب الأجزاء، تطبيع E.164 حسب الدولة |
| `tests/Feature/SmsModuleTest.php` | الصلاحيات، CRUD providers+accounts، تشفير عند الراحة، حفظ الأسرار على التعديل، حساب افتراضي واحد، readiness، اختبار اتصال، draft tests، balance (قابلية)، إرسال رسالة اختبارية (تطبيع + E.164 إلى المزوّد)، قوائم dropdown |
| `tests/Feature/SmsProviderConfigTest.php` | تشفير/حفظ config المزوّد، رفض الناقص، حفظ الأسرار عند التعديل، عدم كشف الأسرار في `configuration_meta`، test-draft (نجاح/دمج/422/403) |
| `tests/Feature/SmsProviderUpdateTest.php` | تحديثات المزوّد |

**النتيجة:** `vendor/bin/phpunit` → **333 tests, 1294 assertions — OK** (كانت 348 قبل حذف الـ adapters الخمسة لأن اختبارات الـ DataProvider كانت تعمل على 7 مفاتيح).

---

## 9. نقاط محسومة / ملاحظات

- قرار تم اعتماده (لم يجب المستخدم): **config المزوّد يُحفظ على السجل نفسه** والخيار الأفضل المعروض؛ حساب الإرسال و`send pipeline` لم يتغيّرا — الحساب يبقى مصدر الحقيقة للإرسال، وconfig المزوّد مجرد افتراضي اختياري + دعم test-draft.
- لا يزال مستحقاً (لم يُنفَّذ): الدمج في شاشات المستخدم/المزوّد (لا يوجد SMS في SPAs المستخدم/المزوّد) — الـ Provider SPA لا يملك chat لكن SMS يبقى Admin-only حالياً.
- قائمة المزوّدين الحالية في مودال الإضافة: `["Twilio", "SMS Misr"]` (مُتحقّق حي).

---

## 10. الملفات الرئيسية (مرجع سريع)

**Backend:**
- `Modules/Sms/routes/admin.php`
- `Modules/Sms/app/Http/Controllers/SmsProviderController.php` · `SmsAccountController.php`
- `Modules/Sms/app/Services/Sms/SmsAdapterRegistry.php` · `SmsProviderService.php` · `SmsAccountService.php` · `SmsService.php` · `SmsAvailabilityService.php` · `SmsMessageHelper.php` · `PhoneNumberNormalizer.php`
- `Modules/Sms/app/Services/Sms/Adapters/TwilioSmsAdapter.php` · `SmsMisrSmsAdapter.php` · `BaseSmsAdapter.php`
- `Modules/Sms/app/Models/SmsProvider.php` · `SmsAccount.php`
- `Modules/Sms/app/Http/Requests/SmsProviderRequest.php` · `SmsAccountRequest.php` · `SmsSendTestRequest.php`
- `Modules/Sms/app/Http/Resources/SmsProviderResource.php` · `SmsAccountResource.php`
- `Modules/Sms/database/migrations/*.php` · `Modules/Sms/config/config.php`
- `lang/en/sms.php` · `lang/ar/sms.php`

**Frontend:**
- `resources/js/modules/admin/routes.js`
- `resources/js/modules/admin/themes/theme-1/views/sms-providers/index.vue` · `ModalCreateAndUpdate.vue`
- `resources/js/modules/admin/themes/theme-1/views/sms-accounts/index.vue` · `ModalCreateAndUpdate.vue`
- `resources/js/composables/useSmsProviders.js` · `useSmsAccounts.js` · `useSmsConfirmDelete.js`
- `resources/js/stores/smsProviders.js` · `smsAccounts.js`
- `resources/js/components/ui/TestStatusBadge.vue`
- `resources/js/locales/en.json` · `ar.json`

**الاختبارات:**
- `tests/Unit/SmsAdaptersTest.php`
- `tests/Feature/SmsModuleTest.php` · `SmsProviderConfigTest.php` · `SmsProviderUpdateTest.php`