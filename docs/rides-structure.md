# DORR Rides — بنية قاعدة البيانات (DRAFT)

> **الحالة (2026-10-13): مسودة، مش معتمدة.** مبنية على [rides-plan.md](rides-plan.md).
> - أي قيمة مكتوب جنبها «افتراض» محتاجة مراجعة.
> - **ممنوع تنفيذ أي migration من الملف ده قبل الاعتماد** (حسب [AGENTS.md](../AGENTS.md)).
> - القرارات اللي محتاجة موافقة قبل التنفيذ في §17.

---

## 0. قواعد عامة

### 0.1 أماكن الكود
| المكان | الجداول |
|---|---|
| `Modules/Driver` (جديد) | حساب السواق، والمستندات، والمركبات وكتالوجاتها، والأهلية لكل خدمة، والحالة والمكان، وسجل المواقع |
| `Modules/Rides` (جديد) | كل حاجة خاصة بالرحلات: الفئات، والسياسات، والرحلات، والعروض، والتسوية، وأرقام السواق في الرحلات، والراكب، ووصل |
| `app/` (General) | `audit_logs` (§9) |
| موديولات موجودة | تعديلات صغيرة (§10) |

**قاعدة:** `Modules/Driver` مبيعرفش حاجة عن الرحلات. أي جدول بيتكلم عن رحلة أو فئة رحلة مكانه `Modules/Rides`.

### 0.2 الأنواع والتسميات
- **الفلوس:**
  - `bigint` بالهللة، ولاحقة `_minor`، زي المحفظة بالظبط، ومعاها `currency_id` لقطة وقت الإنشاء.
  - السالب مسموح بس في الأعمدة اللي مكتوب عليها «بإشارة».
- **النسب:** `unsignedInteger` بالـ basis points ولاحقة `_bp` (2000 = 20%). مفيش `float` ولا `decimal` في النسب.
- **المسافات:** بالمتر، ولاحقة `_m`.
- **المدد:** بالثانية، ولاحقة `_s`.
- **الإحداثيات:** `lat` و`lng` من نوع `decimal(10,7)`، زي `user_addresses` و`discover_cities`.
- **الأوقات:**
  - `timestamp` بتوقيت UTC ولاحقة `_at`.
  - وقت السيرفر هو المرجع في أي قرار (D-117).
- **المعرّف الخارجي:** أي حاجة بتطلع في الـ API ليها `uuid`. الـ `id` الرقمي داخلي بس.
- **الحالات:** PHP enums من نوع `string` (§11)، زي `UserStatus` و`Gender`.
- **التفعيل في جداول الإعدادات:** `status` boolean، والافتراضي `Status::Active`.
- **الترجمة:** جدول `..._translations` فيه `locale` + `unique(parent_id, locale)`.
- **التعليقات:** كل عمود عليه تعليق عربي في الـ migration.
- **الحذف:**
  - `softDeletes()` على جداول الإعدادات والكتالوجات بس.
  - الجداول التشغيلية والمالية مفيهاش حذف خالص، والتصحيح بيتعمل بصف جديد.
- **جداول بتتضاف بس (Append-only):** مفيش update ولا delete بعد الإضافة، ومفيش `updated_at`:
  - `ride_events`، و`ride_price_lines`، و`ride_adjustments`، و`driver_points_ledger`، و`driver_location_log`، و`wasl_logs`، و`audit_logs`.
  - **استثناء واحد:** حذف `driver_location_log` بعد فترة الاحتفاظ (§14).
- **الـ morph aliases:**
  - alias قصير: `user` / `driver` / `admin` / `system`.
  - بيتقري من mapping جوه الموديول (`Modules\Rides\Support\ActorType`)، **مش** من `Relation::morphMap()` العام. نفس سبب المحفظة والشات.
- **قيود MySQL بس** (CHECK، والـ generated columns): بتتحط جوه `if (DB::getDriverName() === 'mysql')` زي migrations المحفظة، لأن الاختبارات شغالة على SQLite.

### 0.3 الأماكن والمسافات (من غير SPATIAL)
الاختبارات شغالة على SQLite، فمفيش `POINT` ولا `SPATIAL INDEX` ولا دوال `ST_*` في المرحلة 1. بنمشي على نفس أسلوب الشات وDiscover:
- **أقرب سواقين:**
  - مستطيل حوالين النقطة (bounding box) على `lat` و`lng`، وعليهم index.
  - بعدين المسافة الدقيقة بـ haversine في PHP، على العدد الصغير اللي طلع.
- **هل النقطة جوه منطقة:**
  - الـ polygon متخزن JSON، ومعاه حدود المستطيل (`min_lat` / `max_lat` / `min_lng` / `max_lng`) للفلترة الأولى.
  - التشيك الدقيق (point-in-polygon) في PHP. المناطق قليلة وبتتكاش.
- **لو العدد كبر جدًا:**
  - نضيف عمود `POINT SRID 4326` و`SPATIAL INDEX`، على MySQL بس.
  - ده بيتعمل ورا نفس الـ interface (`NearbyDrivers`)، فالكود اللي فوقه مش هيتغير.

### 0.4 المدينة
- **`city_id`** في كل الجداول = `discover_cities.id`، **من غير foreign key**، بالظبط زي `chat_feature_rules.city_id`.
- **ليه `discover_cities`:** الشات بيستخدمه سجل للمدن، وفيه المنطقة الزمنية والاسم بكل لغة.
- **اسم المدينة لوصل مش من الترجمة (قرار المالك 2026-10-13):** وصل بيرفض الرحلة لو الاسم فيه حرف واحد غلط. عشان كده الاسم بييجي من جدول ربط فيه **الاسم الرسمي بالحرف** (`ride_regulator_cities`، §7.3)، مش من ترجمة المدينة.
- **تحديد المدينة لأي نقطة:**
  1. الأول من مناطق `service_area` (polygon).
  2. لو مفيش، أقرب مدينة (نفس `ChatFeatures::nearestCity`).
- **لو سجل المدن اتنقل لـ General بعدين،** العمود هيفضل زي ما هو.

### 0.5 منع التكرار والتزامن
- **`Idempotency-Key`:**
  - بيتخزن على الصف اللي الأمر بيعمله (`rides`، و`ride_events`، و`ride_bids`، و`driver_location_batches`)، ومعاه `request_hash` (SHA-256).
  - نفس المفتاح بنفس المحتوى بيرجّع نفس النتيجة. وبمحتوى مختلف بيرجّع 409، زي المحفظة.
- **أقفال النشاط (`ride_active_locks`، §3.6):**
  - صف بمفتاح unique لكل حالة من دول: سواق عنده رحلة نشطة، وراكب عنده رحلة نشطة، وسواق عنده عرض سعر شغال.
  - القبول = إدخال القفل + تحديث مشروط للرحلة، في نفس الـ transaction. لو القفل موجود، الإدخال بيفشل، فمستحيل سواق ياخد رحلتين.
  - الطريقة دي بتشتغل على MySQL وSQLite.
- **`rides.version`:** optimistic lock على أي تحديث للرحلة (D-350).

---

## 1. `Modules/Driver`

### 1.1 `drivers`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `name` | `string(150)` | nullable | الاسم اللي بيظهر |
| `name_ar` / `name_en` | `string(150)` | nullable | الاسم بالعربي والإنجليزي، زي ما وصل رجّعه |
| `phone` | `string(20)` | unique | E.164 (`PhoneNumberNormalizer`) |
| `phone_verified_at` | `timestamp` | nullable | |
| `email` | `string` | nullable, unique | |
| `gender` | `enum Gender` | nullable | male / female (لفئة «لكِ سيدتي») |
| `date_of_birth` | `date` | nullable | ميلادي. والهجري بيتحسب وقت الإرسال لوصل |
| `national_id` | `string(20)` | nullable | رقم الهوية أو الإقامة |
| `id_type` | `string(16)` | nullable | `national_id` / `iqama` / `passport` |
| `nationality_country_id` | `foreignId → countries` | nullable, `nullOnDelete` | |
| `country_id` | `foreignId → countries` | NOT NULL, `restrictOnDelete` | دولة الشغل |
| `city_id` | `unsignedBigInteger` | nullable | مدينة الشغل (§0.4) |
| `locale` | `string(10)` | default `ar` | لغة الإشعارات |
| `status` | `enum DriverStatus` | default `pending_documents` | §11 |
| `status_reason` | `string` | nullable | سبب الإيقاف أو التقييد، بصياغة آمنة (D-9) |
| `registered_by` | `string(8)` | default `app` | `app` / `admin` |
| `registered_by_admin_id` | `foreignId → admins` | nullable, `nullOnDelete` | لو اتسجل من لوحة التحكم |
| `activated_at` | `timestamp` | nullable | أول تفعيل |
| `onboarding_ends_at` | `timestamp` | nullable | نهاية فترة التأهيل: 30 يوم من التفعيل (D-5) |
| `last_active_at` | `timestamp` | nullable | |
| `created_at` / `updated_at` / `deleted_at` | | | `softDeletes` للحساب بس، مش لعملياته |

- **الصورة الشخصية:** media collection `avatar`.
- **Traits:** `HasApiTokens`، و`HasVerificationCodes`، و`HasNotificationDevices`، و`HasWallets`، و`HasReferralCode`، و`HasMediaTrait`، و`Notifiable`، و`SoftDeletes`.
- **فهارس:** `unique(phone)`، و`unique(id_type, national_id)`، و`index(country_id, status)`، و`index(city_id, status)`.
- **حسابات منفصلة:** نفس الرقم ممكن يكون موجود في `users` كراكب، لأن الجدولين منفصلين.

### 1.2 `driver_document_types` (+ `_translations`)
كتالوج المستندات. أمثلة: الرخصة، والاستمارة، والتأمين، والفحص الدوري، والتفويض، ورخصة الباص، ورخصة الدراجة، ورخصة القارب.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `code` | `string(48)` | unique | مفتاح ثابت في الكود (`driving_license`، و`vehicle_registration`…) |
| `applies_to` | `string(8)` | NOT NULL | `driver` / `vehicle` |
| `has_number` / `has_expiry` / `needs_back_side` | `boolean` | default false | الحقول اللي بتظهر في الفورم |
| `expiry_alert_days` | `unsignedSmallInteger` | default 30 | التنبيه قبل الانتهاء بكام يوم (D-16) |
| `status` | `boolean` | default Active | |
| `sort_order` | `unsignedInteger` | default 0 | |
| timestamps + `softDeletes` | | | |

- **الترجمة:** `name`، و`instructions` (إزاي السواق يصوّر المستند).

### 1.3 `driver_document_requirements`
المستند مطلوب في أنهي دولة وأنهي خدمة، ومن المواطن ولا المقيم ولا الاتنين.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `document_type_id` | `foreignId → driver_document_types` | `cascadeOnDelete` | |
| `country_id` | `foreignId → countries` | `cascadeOnDelete` | |
| `service_category_id` | `foreignId → service_categories` | nullable, `cascadeOnDelete` | `null` = مطلوب لأي خدمة في الدولة |
| `residency` | `string(10)` | nullable | `null` = مطلوب من الاتنين، أو `citizen` / `resident` بس (2026-10-14) |
| `is_required` | `boolean` | default true | |
| timestamps | | | |

- **unique:** `(document_type_id, country_id, service_category_id, residency)`.
- **`drivers.residency`** (`citizen` / `resident`، الافتراضي `citizen`): الأدمن بيحدده في أي دولة، وعلى أساسه بتتحدد المستندات المطلوبة.
- **المستندات الخاصة بفئة رحلة** (زي رخصة الباص) مكانها `ride_category_document_types` (§2.2).

### 1.4 `driver_documents`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `driver_id` | `foreignId → drivers` | `cascadeOnDelete` | |
| `vehicle_id` | `foreignId → vehicles` | nullable, `cascadeOnDelete` | لو المستند خاص بمركبة |
| `document_type_id` | `foreignId → driver_document_types` | `restrictOnDelete` | |
| `number` | `string(64)` | nullable | |
| `expires_at` | `date` | nullable | |
| `status` | `enum DocumentStatus` | default `pending` | pending / approved / rejected / expired / replaced |
| `rejection_reason` | `string` | nullable | بيظهر للسواق |
| `reviewed_by_admin_id` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `reviewed_at` | `timestamp` | nullable | |
| timestamps | | | |

- **الملفات:** media collections `front` و`back`.
- **التاريخ:** لما مستند جديد يتوافق عليه، القديم من نفس النوع بيبقى `replaced`، ومبيتمسحش.
- **فهارس:** `index(driver_id, document_type_id, status)`، و`index(status, expires_at)` (لأمر التنبيه وأمر الانتهاء).

### 1.5 كتالوجات المركبات (+ ترجمات)
| الجدول | الأعمدة |
|---|---|
| `vehicle_makes` | `id`، و`status`، و`sort_order` + ترجمة `name` |
| `vehicle_models` | `id`، و`make_id`، و`body_type` (§11)، و`seats`، و`is_luxury` (لـ VIP)، و`status` + ترجمة `name` |
| `vehicle_colors` | `id`، و`hex` (`char(7)`)، و`status` + ترجمة `name` |

- الكتالوجات التلاتة عليها `softDeletes`.

### 1.6 `vehicles`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `driver_id` | `foreignId → drivers` | `cascadeOnDelete` | |
| `country_id` | `foreignId → countries` | `restrictOnDelete` | دولة اللوحة |
| `make_id` / `model_id` / `color_id` | `foreignId` | `restrictOnDelete` | |
| `year` | `unsignedSmallInteger` | | سنة الصنع |
| `body_type` | `enum VehicleBodyType` | | لقطة من الموديل، وممكن تتعدل |
| `seats` | `unsignedTinyInteger` | | من غير السواق |
| `plate_letters` | `string(8)` | | الحروف بالعربي، مثلًا `ا ب ح`. وصل بيقسمها يمين ووسط وشمال |
| `plate_number` | `string(8)` | | الأرقام |
| `plate_type` | `unsignedSmallInteger` | | كود نوع اللوحة عند وصل |
| `sequence_number` | `string(16)` | nullable | الرقم التسلسلي للاستمارة (لوصل) |
| `features` | `json` | nullable | `child_seat`، و`wheelchair_ramp`، و`pet_friendly`، و`large_luggage`… |
| `status` | `enum VehicleStatus` | default `pending` | pending / approved / rejected / inactive |
| `is_active` | `boolean` | default false | المركبة اللي شغال بيها دلوقتي. واحدة بس لكل سواق (D-22) |
| `approved_by_admin_id` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `approved_at` | `timestamp` | nullable | |
| timestamps + `softDeletes` | | | |

- **الصور:** media collection `photos` (D-24).
- **unique:** `(country_id, plate_letters, plate_number, plate_type)`، و`(country_id, sequence_number)`.
- **مركبة نشطة واحدة:** القاعدة دي بتتطبق في `VehicleService` جوه transaction، لأن SQLite مبيدعمش partial unique.

### 1.7 `driver_service_eligibility`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `driver_id` | `foreignId → drivers` | `cascadeOnDelete` | |
| `service_category_id` | `foreignId → service_categories` | `cascadeOnDelete` | «ركاب» النهارده، وبعدين التوصيل أو الطرود |
| `status` | `enum EligibilityStatus` | | eligible / ineligible / suspended / pending |
| `reason` | `string` | nullable | |
| `decided_by_admin_id` | `foreignId → admins` | nullable, `nullOnDelete` | |
| `decided_at` | `timestamp` | nullable | |
| timestamps | | | |

- **unique:** `(driver_id, service_category_id)`.

### 1.8 `driver_presence`
صف واحد لكل سواق: حالته ومكانه دلوقتي. التوزيع والخريطة الحية بيقروا منه.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `driver_id` | `foreignId → drivers` | PK, `cascadeOnDelete` | |
| `status` | `enum PresenceStatus` | default `offline` | offline / online / paused / on_trip |
| `lat` / `lng` | `decimal(10,7)` | nullable | آخر مكان |
| `heading` | `unsignedSmallInteger` | nullable | من 0 لـ 359 |
| `speed_mps` | `decimal(6,2)` | nullable | |
| `accuracy_m` | `unsignedSmallInteger` | nullable | |
| `city_id` | `unsignedBigInteger` | nullable | المدينة اللي هو فيها (§0.4) |
| `gps_enabled` | `boolean` | default true | |
| `gps_off_since` | `timestamp` | nullable | §10.4 في الخطة |
| `last_ride_requested` | `boolean` | default false | «آخر رحلة» (D-400) |
| `paused_until` | `timestamp` | nullable | الاستراحة |
| `online_since` | `timestamp` | nullable | |
| `last_seen_at` | `timestamp` | nullable | وقت آخر نقطة وصلت |
| `app_version` | `string(20)` | nullable | |
| `updated_at` | `timestamp` | | |

- **فهارس:** `index(status, lat)`، و`index(status, city_id)`، و`index(last_seen_at)` (للـ sweeper).

### 1.9 `driver_presence_sessions`
فترات الـ Online. منها بتتحسب «ساعات الشغل» في الإحصائيات.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `driver_id` | `foreignId → drivers` | `cascadeOnDelete` | |
| `started_at` | `timestamp` | | |
| `ended_at` | `timestamp` | nullable | |
| `end_reason` | `string(16)` | nullable | manual / stale / gps_off / last_ride / admin / suspended |

- **فهارس:** `index(driver_id, started_at)`.

### 1.10 `driver_location_batches` و`driver_location_log`
**`driver_location_batches`:** بيمنع تسجيل نفس الدفعة مرتين (§10.2 في الخطة).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `driver_id` | `foreignId → drivers` | `cascadeOnDelete` | |
| `batch_uuid` | `uuid` | `unique(driver_id, batch_uuid)` | من الموبايل (Idempotency) |
| `points_count` | `unsignedSmallInteger` | | |
| `first_recorded_at` / `last_recorded_at` | `timestamp(3)` | | |
| `clock_offset_ms` | `integer` | | الفرق بين ساعة الموبايل والسيرفر وقت الإرسال |
| `received_at` | `timestamp` | | |

**`driver_location_log`** (بيتضاف بس):

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `driver_id` | `foreignId → drivers` | من غير cascade | دليل |
| `ride_id` | `unsignedBigInteger` | nullable | الرحلة لو فيه. **من غير FK**، لأن `Modules/Driver` مبيعرفش الرحلات |
| `batch_id` | `foreignId → driver_location_batches` | | |
| `lat` / `lng` | `decimal(10,7)` | | |
| `accuracy_m` | `unsignedSmallInteger` | nullable | |
| `speed_mps` | `decimal(6,2)` | nullable | |
| `heading` | `unsignedSmallInteger` | nullable | |
| `recorded_at` | `timestamp(3)` | | **الوقت الحقيقي:** ساعة الجهاز اللي مبتتغيرش + الفرق مع السيرفر (§10.5 في الخطة) |
| `device_time` | `timestamp(3)` | nullable | الوقت اللي الموبايل كتبه، للمقارنة بس |
| `has_customer` | `boolean` | default false | لوصل |
| `is_mock` | `boolean` | default false | موقع وهمي |
| `wasl_sent_at` | `timestamp` | nullable | اتبعت لوصل إمتى |
| `created_at` | `timestamp` | | من غير `updated_at` |

- **فهارس:** `index(driver_id, recorded_at)`، و`index(ride_id, recorded_at)`، و`index(wasl_sent_at, id)`.
- **اللي بيتخزن:** نقطة كل 30 ثانية والسواق فاضي، وكل 15 ثانية في الرحلة. الموبايل بيبعت كل 5 ثواني في الرحلة عشان الراكب يشوف العربية، بس اللي بيتخزن أقل (§14).
- **الاحتفاظ:**
  - 30 يوم (من السياسة)، وأمر يومي بيمسح اللي أقدم على دفعات.
  - قبل المسح، مسار كل رحلة بيتحفظ مضغوط في `ride_tracks` (§3.13).
- **الفجوات:**
  - لو الـ GPS اتقفل، بيتسجل حدث `gps_off` / `gps_on` في `ride_events` لو فيه رحلة.
  - وكمان بيتسجل في `driver_presence`.

---

## 2. `Modules/Rides`: الكتالوج والإعدادات

### 2.1 `ride_categories` (+ `_translations`)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `slug` | `string(32)` | unique | `economy`، و`with_me`، و`ladies`، و`vip`، و`offers`، و`accessible`، و`family`، و`intercity`، و`bus`، و`motorcycle`، و`marine` |
| `pricing_strategy` | `enum PricingStrategy` | | distance / trip_flat / bid / seat / time |
| `capacity_min` / `capacity_max` | `unsignedTinyInteger` | | عدد الركاب |
| `eligibility` | `json` | | شروط السواق والمركبة: `body_types`، و`min_year`، و`min_seats`، و`required_features`، و`driver_gender`، و`rider_gender`، و`min_tier`، و`luxury_only` |
| `flags` | `json` | | `cash_allowed`، و`surge_allowed`، و`open_destination_allowed`، و`requires_schedule`، و`pin_required`، و`multi_stop_allowed` |
| `color` | `char(7)` | nullable | لون الفئة في التطبيق |
| `status` | `boolean` | default Active | |
| `sort_order` | `unsignedInteger` | default 0 | |
| timestamps + `softDeletes` | | | |

- **الترجمة:** `name`، و`tagline` (سطر تحت الاسم)، و`description`.
- **الأيقونة والرسمة:** media collections `icon` و`illustration`.

### 2.2 `ride_category_document_types`
| العمود | الوصف |
|---|---|
| `category_id` + `document_type_id` | مستندات إضافية لازمة للفئة (زي رخصة الباص أو القارب). `unique` على الاتنين |

### 2.3 `ride_feature_rules`
نفس `chat_feature_rules` بالظبط (§5 في الخطة).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `feature` | `string(48)` | | `rides` (الخدمة كلها)، و`category.{slug}`، و`bidding`، و`multi_stop`، و`open_destination`، و`scheduled`، و`book_for_other`، و`cash`، و`wallet`، و`card`، و`tips`، و`pin`، و`share`، و`change_driver`، و`driver_points`، و`one_touch` |
| `country_id` | `foreignId → countries` | `cascadeOnDelete` | |
| `city_id` | `unsignedBigInteger` | nullable | `null` = الدولة كلها |
| `enabled` | `boolean` | default false | |
| `note` | `string(255)` | nullable | سبب للإدارة: رخصة، أو تجربة… |
| timestamps | | | |

- **unique:** `(feature, country_id, city_id)`.
- **الأولوية:** قاعدة المدينة بتغلب قاعدة الدولة.
- **مفيش قاعدة = مقفول.** التشغيل لازم يكون صريح.

### 2.4 `ride_policies`
كل الأرقام اللي بتتغير من اللوحة. كل صف = نطاق + JSON.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `country_id` | `foreignId → countries` | `cascadeOnDelete` | |
| `city_id` | `unsignedBigInteger` | nullable | |
| `zone_id` | `foreignId → ride_zones` | nullable, `cascadeOnDelete` | |
| `category_id` | `foreignId → ride_categories` | nullable, `cascadeOnDelete` | |
| `data` | `json` | | المفاتيح وقيمها الافتراضية في §12 |
| `status` | `boolean` | default Active | |
| `updated_by_admin_id` | `foreignId → admins` | nullable, `nullOnDelete` | |
| timestamps | | | |

- **unique:** `(country_id, city_id, zone_id, category_id)`.
- **الترتيب من الأعم للأخص** (كل مفتاح لوحده بياخد قيمته من أخص صف فيه):
  1. الدولة.
  2. الدولة + الفئة.
  3. المدينة.
  4. المدينة + الفئة.
  5. المنطقة.
  6. المنطقة + الفئة.
- **الأسعار جوه الـ JSON** (`price.*`، §12). يعني تسعير فئة في مدينة = صف «مدينة + فئة».
- **التاريخ:**
  - كل تعديل بيتسجل في `audit_logs` بالقيمة قبل وبعد (§9).
  - الرحلة بتحفظ كل القيم اللي اتطبقت عليها في `rides.confirmed_snapshot`، فأي تعديل بعد كده مبيأثرش عليها (D-371).
- **التحقق:** `RidePolicyData` (DTO) بيرفض أي مفتاح مش معروف أو قيمة غلط قبل الحفظ.

### 2.5 `ride_tax_rules` (+ `_translations`)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `country_id` | `foreignId → countries` | `cascadeOnDelete` | |
| `code` | `string(32)` | | `vat` |
| `base` | `enum TaxBase` | | commission / fare / fare_and_fees |
| `rate_bp` | `unsignedInteger` | | 1500 = 15% |
| `borne_by` | `enum TaxBearer` | | driver / rider |
| `effective_from` | `timestamp` | | |
| `effective_to` | `timestamp` | nullable | |
| `status` | `boolean` | default Active | |
| timestamps + `softDeletes` | | | |

- **الترجمة:** `label`، مثلًا «ضريبة القيمة المضافة على عمولة التطبيق».
- **قيم السعودية في الـ seed (زي Jawad):** `vat`، و`commission`، و1500، و`driver`.

### 2.6 `ride_zones` (+ `_translations`)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `country_id` | `foreignId → countries` | `cascadeOnDelete` | |
| `city_id` | `unsignedBigInteger` | nullable | |
| `type` | `enum ZoneType` | | service_area / blocked / airport / surcharge / pickup_only |
| `polygon` | `json` | | `[[lat, lng], …]` |
| `min_lat` / `max_lat` / `min_lng` / `max_lng` | `decimal(10,7)` | | حدود المستطيل للفلترة الأولى (§0.3) |
| `config` | `json` | nullable | حسب النوع: `surcharge_minor` أو `surcharge_per_km_minor` للـ surcharge، و`fee_minor` للمطار… |
| `priority` | `unsignedSmallInteger` | default 0 | لو فيه منطقتين فوق بعض |
| `status` | `boolean` | default Active | |
| timestamps + `softDeletes` | | | |

- **الترجمة:** `name`.
- **فهارس:** `index(country_id, type, status)`، و`index(min_lat, max_lat)`.

### 2.7 `ride_pickup_points` (+ `_translations`)
نقاط الالتقاء المعتمدة في المطارات والمولات والمستشفيات (R-16، D-111).

| العمود | الوصف |
|---|---|
| `id`، و`zone_id` (`cascadeOnDelete`)، و`lat` / `lng`، و`kind` (gate / terminal / entrance / parking)، و`status`، و`sort_order` + `softDeletes` | |

- **الترجمة:** `name`، و`instructions`.

### 2.8 أسباب الإلغاء ووسوم التقييم (+ ترجمات)
| الجدول | الأعمدة |
|---|---|
| `ride_cancel_reasons` | `actor` (rider / driver / both)، و`stages` (JSON بالمراحل اللي السبب بيظهر فيها)، و`is_safety` (إلغاء أمني له مسار لوحده، D-398)، و`requires_note`، و`status`، و`sort_order` + ترجمة `label` |
| `ride_rating_tags` | `target` (driver / rider)، و`sentiment` (positive / negative)، و`status`، و`sort_order` + ترجمة `label` |

### 2.9 `driver_points_rules`
| العمود | النوع | الوصف |
|---|---|---|
| `id` | `bigIncrements` | |
| `country_id` | `foreignId → countries` nullable | `null` = كل الدول |
| `event` | `string(32)` | `trip_completed`، و`on_time_pickup`، و`five_stars`، و`challenge`، و`late_cancel` (بالسالب) |
| `points` | `integer` | بإشارة |
| `conditions` | `json` nullable | مثال للالتزام بالموعد: `[{"max_minutes_late": 0, "points": 20}, {"max_minutes_late": 3, "points": 10}]` (فكرة `distance_points` في LeeTaxi) |
| `status` | `boolean` | |
| timestamps + `softDeletes` | | |

---

## 3. `Modules/Rides`: الرحلة

### 3.1 `ride_quotes`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | `quote_id` في الـ API |
| `user_id` | `foreignId → users` | `cascadeOnDelete` | الراكب |
| `country_id` / `city_id` / `currency_id` | | | |
| `category_id` | `foreignId → ride_categories` | | |
| `pricing_mode` | `enum PricingMode` | | upfront / metered |
| `origin_lat` / `origin_lng` | `decimal(10,7)` | | |
| `destination_lat` / `destination_lng` | `decimal(10,7)` | nullable | `null` = من غير وجهة |
| `stops` | `json` | nullable | `[{lat, lng, address}]` |
| `route_key` | `char(40)` | | مفتاح كاش المسار: SHA-1 للنقط بعد التقريب + فترة زمنية |
| `distance_m` / `duration_s` | `unsignedInteger` | nullable | |
| `polyline` | `mediumText` | nullable | من Google مرة واحدة لكل الفئات |
| `lines` | `json` | | أسطر السعر (§3.11) |
| `total_minor` | `bigint` | nullable | للسعر المثبت |
| `estimate_min_minor` / `estimate_max_minor` | `bigint` | nullable | للحساب بالمسافة |
| `bid_floor_minor` | `bigint` | nullable | أقل سعر للراكب في العروض (§9.1 في الخطة) |
| `version` | `unsignedSmallInteger` | default 1 | |
| `fingerprint` | `char(64)` | | SHA-256 للأسطر + الإجمالي + الفئة + المسار |
| `policy_snapshot` | `json` | | القيم اللي اتسعّر بيها |
| `expires_at` | `timestamp` | | |
| `created_at` | `timestamp` | | |

- **فهارس:** `index(user_id, created_at)`، و`index(expires_at)`.
- **التنضيف:** الـ Quote اللي مطلعش منه رحلة بيتمسح بعد 7 أيام (أمر يومي).

### 3.2 `rides`
الجدول الأساسي، ومفيش فيه حذف.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `number` | `string(24)` | unique | رقم مقروء بنفس شكل الـ Wireflow: `DR-250520-1048` (§3.14). وهو `tripId` في وصل |
| `user_id` | `foreignId → users` | `restrictOnDelete` | الراكب |
| `beneficiary` | `json` | nullable | لو الحجز لشخص تاني: الاسم والموبايل (المرحلة 3) |
| `category_id` | `foreignId → ride_categories` | `restrictOnDelete` | |
| `country_id` / `city_id` / `currency_id` | | | |
| `status` | `enum RideStatus` | | §11 |
| `booking_mode` | `enum BookingMode` | | instant / scheduled / bid |
| `pricing_mode` | `enum PricingMode` | | upfront / metered |
| `open_destination` | `boolean` | default false | |
| `quote_id` | `foreignId → ride_quotes` | nullable, `nullOnDelete` | |
| `confirmed_snapshot` | `json` | | **مبيتعدلش بعد التأكيد:** الأسطر، وكل قيم السياسة، وقاعدة الضريبة، والسعر المتفق عليه |
| `rider_offer_minor` | `bigint` | nullable | سعر الراكب دلوقتي، في العروض |
| `agreed_price_minor` | `bigint` | nullable | السعر المتفق عليه، في المثبت والعروض |
| `final_total_minor` | `bigint` | nullable | اللي الراكب دفعه فعلًا |
| `commission_minor` / `tax_minor` / `driver_net_minor` | `bigint` | nullable | القيم النهائية، للتقارير |
| `payment_method` | `enum RidePaymentMethod` | | wallet / card / cash |
| `payment_status` | `enum RidePaymentStatus` | | §11 |
| `topup_payment_transaction_id` | `foreignId → payment_transactions` | nullable, `nullOnDelete` | لو الدفع بالبطاقة (شحن الفرق) |
| `driver_id` | `foreignId → drivers` | nullable, `restrictOnDelete` | |
| `vehicle_id` | `foreignId → vehicles` | nullable, `restrictOnDelete` | |
| `vehicle_snapshot` | `json` | nullable | اللوحة واللون والموديل وقت القبول، للفاتورة والتاريخ |
| `pin` | `string(4)` | nullable | الرمز اللي الراكب بيقوله للكابتن؛ بيتخزن عادي (الراكب لازم يشوفه في التطبيق)، و5 محاولات غلط بتقفله |
| `pin_attempts` | `unsignedTinyInteger` | default 0 | |
| `origin_lat` / `origin_lng` | `decimal(10,7)` | | |
| `origin_address` | `string(255)` | | |
| `destination_lat` / `destination_lng` | `decimal(10,7)` | nullable | في الرحلة من غير وجهة، بتبقى آخر نقطة بعد الإنهاء |
| `destination_address` | `string(255)` | nullable | |
| `origin_city_ar` / `destination_city_ar` | `string(80)` | nullable | الاسم الرسمي لوصل، من `ride_regulator_cities` بالحرف (§7.3). `null` = المدينة ملهاش اسم مسجّل |
| `planned_polyline` | `mediumText` | nullable | |
| `planned_distance_m` / `planned_duration_s` | `unsignedInteger` | nullable | |
| `metered_distance_m` / `metered_duration_s` | `unsignedInteger` | nullable | الفعلي (§10 في الخطة) |
| `distance_source` | `enum DistanceSource` | nullable | device_odometer / server_track / route_estimate |
| `gps_gap_s` | `unsignedInteger` | default 0 | |
| `pickup_waiting_s` | `unsignedInteger` | default 0 | |
| `stops_waiting_s` | `unsignedInteger` | default 0 | |
| `search_radius_m` | `unsignedInteger` | nullable | النطاق الحالي (للرادار عند الراكب) |
| `dispatch_round` | `unsignedSmallInteger` | default 0 | |
| `scheduled_at` | `timestamp` | nullable | موعد الحجز المسبق (R-12) |
| `reminded_at` | `timestamp` | nullable | اتبعت تذكير الموعد مرة واحدة (R-170، D-266) |
| `reserved_driver_id` | `FK drivers` | nullable، nullOnDelete | الكابتن اللي حجز الرحلة المجدولة مقدماً (D-264)؛ `index(reserved_driver_id, status)` |
| `reserved_at` | `timestamp` | nullable | |
| `search_started_at` | `timestamp` | nullable | البحث بدأ امتى فعلاً (الرحلة المجدولة بتتحجز قبلها بكتير) |
| `requested_at` / `accepted_at` / `arrived_at` / `started_at` / `completed_at` / `canceled_at` / `expired_at` | `timestamp` | nullable | وقت السيرفر |
| `cancel_actor` | `enum CancelActor` | nullable | rider / driver / platform / system |
| `cancel_stage` | `enum CancelStage` | nullable | before_accept / after_accept / after_arrival / in_progress |
| `cancel_reason_id` | `foreignId → ride_cancel_reasons` | nullable, `nullOnDelete` | |
| `cancel_note` | `string(255)` | nullable | |
| `chat_conversation_id` | `unsignedBigInteger` | nullable | شات الرحلة (§10) |
| `wasl_status` | `enum WaslTripStatus` | nullable | pending / sent / failed / not_required |
| `wasl_attempts` | `unsignedTinyInteger` | default 0 | |
| `wasl_last_attempt_at` / `wasl_sent_at` | `timestamp` | nullable | |
| `wasl_result_code` | `string(64)` | nullable | |
| `wasl_result_message` | `string(255)` | nullable | رسالة وصل (جواد: `wasl_registration_result_msg`) |
| `wasl_origin_city` / `wasl_destination_city` | `string(64)` | nullable | الاسم الرسمي اللي اتبعت لوصل (جواد: `pickup_city_name` / `arrive_city_name`) |
| `idempotency_key` | `string(80)` | nullable | من الراكب |
| `request_hash` | `char(64)` | nullable | |
| `version` | `unsignedInteger` | default 0 | optimistic lock |
| timestamps | | | |

- **unique:** `uuid`، و`number`، و`(user_id, idempotency_key)`.
- **فهارس:** `index(user_id, status)`، و`index(driver_id, status)`، و`index(status, scheduled_at)`، و`index(country_id, city_id, status)`، و`index(wasl_status, wasl_last_attempt_at)`، و`index(created_at)`.
- **قاعدة:** أي تغيير في `status` بيعدّي على `RideStateMachine` بس، وبيكتب صف في `ride_events` في نفس الـ transaction.

### 3.3 `ride_holds`
الرحلة ممكن يبقى ليها أكتر من حجز:
- جزء من `spend_only` وجزء من `withdrawable`.
- زيادة لما الراكب يوافق على عرض أعلى أو على تغيير.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `ride_id` | `foreignId → rides` | | |
| `wallet_hold_id` | `foreignId → wallet_holds` | unique | |
| `reason` | `string(16)` | | initial / bid_increase / change_request / open_trip |
| `amount_minor` | `bigint` | | |
| `created_at` | `timestamp` | | |

- **عند الإنهاء:** `capture` بالمبلغ النهائي متوزّع على الحجوزات (`spend_only` الأول)، والباقي `release`.

### 3.4 `ride_stops`
المحطات اللي في النص بس. البداية والنهاية على جدول `rides`.

| العمود | الوصف |
|---|---|
| `id`، و`ride_id`، و`seq` (1..n)، و`lat` / `lng`، و`address`، و`status` (pending / arrived / done / skipped)، و`arrived_at`، و`departed_at`، و`wait_s`، و`added_by_change_request_id` (nullable) | |

- **unique:** `(ride_id, seq)`.

### 3.5 `ride_events` (بيتضاف بس)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `ride_id` | `foreignId → rides` | | |
| `type` | `string(40)` | | `RideEventType` (§11) |
| `from_status` / `to_status` | `string(16)` | nullable | |
| `actor_type` | `string(8)` | | user / driver / admin / system |
| `actor_id` | `unsignedBigInteger` | nullable | |
| `lat` / `lng` | `decimal(10,7)` | nullable | مكان الحدث (دليل) |
| `accuracy_m` | `unsignedSmallInteger` | nullable | |
| `payload` | `json` | nullable | |
| `idempotency_key` | `string(80)` | nullable, unique | للأوامر: القبول، والوصول، والبدء، والإنهاء… |
| `request_hash` | `char(64)` | nullable | |
| `created_at` | `timestamp(3)` | | وقت السيرفر |

- **فهارس:** `index(ride_id, id)`، و`index(type, created_at)`.

### 3.6 `ride_active_locks`
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `lock_key` | `string(40)` | PK | `driver:{id}` (رحلة نشطة)، و`rider:{id}` (رحلة نشطة)، و`driver_bid:{id}` (عرض سعر شغال) |
| `ride_id` | `foreignId → rides` | | |
| `created_at` | `timestamp` | | |

- **بيتضاف** في نفس transaction القبول، أو إنشاء الرحلة، أو العرض.
- **بيتشال** عند الإنهاء، أو الإلغاء، أو الانتهاء، أو الرد على العرض.
- **للأمان:** أمر `rides:dispatch-sweeper` بيشيل أي قفل رحلته خلصت، لو حصل خطأ في النص.

### 3.7 `ride_offers`
الطلب اللي وصل لسواق.

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `ride_id` | `foreignId → rides` | | |
| `driver_id` | `foreignId → drivers` | | |
| `round` | `unsignedSmallInteger` | | |
| `distance_m` / `eta_s` | `unsignedInteger` | | من السواق لحد الراكب |
| `driver_net_minor` | `bigint` | | الصافي اللي ظهر للسواق (D-56) |
| `score` | `integer` | | ترتيبه في الجولة |
| `status` | `enum OfferStatus` | | sent / accepted / declined / expired / withdrawn |
| `auto_accepted` | `boolean` | default false | |
| `sent_at` / `expires_at` / `responded_at` | `timestamp` | | |
| `decline_reason` | `string(32)` | nullable | |

- **unique:** `(ride_id, driver_id, round)`.
- **فهارس:** `index(driver_id, status)`، و`index(status, expires_at)`.
- **في العروض:** الجدول ده بيسجل مين الطلب وصله، عشان الدفعات وإعادة الإرسال بعد الـ Raise. والرد نفسه بيتسجل في `ride_bids`.

### 3.8 `ride_bids`
عروض السعر (§9 في الخطة).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `ride_id` | `foreignId → rides` | | |
| `driver_id` | `foreignId → drivers` | | |
| `attempt` | `unsignedSmallInteger` | | رقم محاولة السواق في الطلب ده |
| `type` | `enum BidType` | | accept_rider_price / counter |
| `rider_price_minor` | `bigint` | | سعر الراكب وقت العرض |
| `bid_price_minor` | `bigint` | | |
| `driver_net_minor` | `bigint` | | صافي السواق لو العرض اتقبل |
| `distance_m` / `eta_s` | `unsignedInteger` | | |
| `status` | `enum BidStatus` | | queued / pending / accepted / declined / expired / withdrawn |
| `shown_at` | `timestamp` | nullable | العرض ظهر للراكب إمتى، والتايمر بيبدأ من الوقت ده |
| `expires_at` | `timestamp` | nullable | `shown_at + bids.ttl_s` |
| `responded_at` | `timestamp` | nullable | |
| `idempotency_key` / `request_hash` | | nullable | |
| timestamps | | | |

- **unique:** `(ride_id, driver_id, attempt)`.
- **فهارس:** `index(ride_id, status)`، و`index(status, expires_at)`.
- **قواعد في الـ Service (مش في الجدول):**
  - في الـ counter: `bid_price_minor > rider_price_minor`، ولحد السقف.
  - عرض واحد شغال للسواق (`driver_bid:{id}` في §3.6).

### 3.9 `ride_price_offers`
| العمود | الوصف |
|---|---|
| `id`، و`ride_id`، و`price_minor`، و`floor_minor`، و`created_at` | سعر الراكب الأول، وكل Raise بعده |

### 3.10 `ride_change_requests` (اتعمل 2026-10-14)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` / `uuid` | | PK / unique | |
| `ride_id` | `foreignId → rides` | cascade | |
| `type` | `string(16)` | | `destination` / `add_stop` |
| `requested_by` | `string(8)` | default `user` | |
| `payload` | `json` | | النقطة الجديدة: `lat` / `lng` / `address` |
| `route` | `json` | nullable | المسار الجديد: `distance_m` / `duration_s` / `polyline` / `estimated` |
| `distance_delta_m` / `duration_delta_s` | `integer` | بإشارة | الفرق عن المسار الحالي |
| `price_delta_minor` / `new_total_minor` / `driver_net_delta_minor` | `bigint` | | فرق السعر، والإجمالي الجديد، وفرق صافي الكابتن |
| `breakdown` | `json` | nullable | أرقام السعر الجديد وسطوره (بتتكتب `rev.N` في `ride_price_lines` لو اتقبل) |
| `rider_consent_at` | `timestamp` | nullable | الراكب وافق وبعته |
| `driver_response` / `driver_responded_at` | `string(16)` / `timestamp` | | `pending` / `accepted` / `rejected` / `expired` |
| `status` | `string(12)` | | `preview` / `pending` / `applied` / `rejected` / `expired` / `canceled` |
| `result_code` | `string(32)` | nullable | |
| `expires_at` | `timestamp` | | المعاينة 3 دقايق، والانتظار `change.driver_response_s` |
| timestamps | | | `index(ride_id, status)`، `index(status, expires_at)` |

### 3.11 `ride_price_lines` (بيتضاف بس)
| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `ride_id` | `foreignId → rides` | | |
| `phase` | `string(12)` | | `confirmed` (وقت التأكيد) أو `final` (عند الإنهاء) |
| `code` | `string(32)` | | `PriceLineCode` (§11) |
| `amount_minor` | `bigint` | | بإشارة (الخصم بالسالب) |
| `visible_to` | `string(8)` | | rider / driver / both / internal |
| `meta` | `json` | nullable | التفاصيل: الكيلومترات وسعرها، والدقايق، والنسبة… |
| `sort_order` | `unsignedSmallInteger` | | |
| `created_at` | `timestamp` | | |

- **قاعدة:**
  - أسطر `confirmed` مبتتعدلش.
  - أي تغيير بيتسجل في `ride_adjustments`.
  - أسطر `final` بتتكتب مرة واحدة بس، عند الإنهاء.

### 3.12 `ride_adjustments` (بيتضاف بس)
| العمود | النوع | الوصف |
|---|---|---|
| `id`، و`ride_id` | | |
| `type` | `string(24)` | waiting_fee / stop_wait_fee / change_request / gps_gap / detour_cap / tip / cancel_fee / no_show_fee / refund / dispute_decision / admin_correction |
| `amount_minor` | `bigint` | بإشارة |
| `affects` | `string(8)` | rider / driver / both |
| `reason_key` | `string(64)` | مفتاح ترجمة للسبب |
| `actor_type` / `actor_id` | | |
| `reference_type` / `reference_id` | | طلب التغيير، أو النزاع… |
| `created_at` | `timestamp` | |

### 3.13 `ride_tracks`
| العمود | النوع | الوصف |
|---|---|---|
| `ride_id` | PK | |
| `polyline` | `mediumText` | مسار الرحلة مضغوط (Encoded polyline)، من `driver_location_log` |
| `points_count` | `unsignedInteger` | |
| `track_distance_m` | `unsignedInteger` | المسافة محسوبة من النقط |
| `device_distance_m` | `unsignedInteger` nullable | عداد الموبايل |
| `gaps` | `json` nullable | الفجوات: من إمتى، ولحد إمتى، والمسافة اللي اتعوّضت |
| `created_at` | `timestamp` | |

- **بيتكتب عند الإنهاء،** وبيفضل حتى بعد ما النقط الخام تتمسح. ده الدليل في النزاعات وقدام وصل.

### 3.14 `ride_counters`
| العمود | الوصف |
|---|---|
| `key` (PK، مثلًا `number:250520`)، و`value`، و`updated_at` | عداد يومي لرقم الرحلة `DR-YYMMDD-NNNN`، بـ `lockForUpdate` |

### 3.15 `ride_shares` — رابط الأهل (اتعمل 2026-10-14)
| العمود | الوصف |
|---|---|
| `ride_id` (cascade)، و`token` `char(40)` unique | الرابط `https://…/ride/{token}` |
| `sharer_type` (`user` / `driver`)، و`sharer_id` | رابط واحد لكل شخص في الرحلة؛ `index(ride_id, sharer_type, sharer_id)` |
| `views`، و`last_viewed_at`، و`revoked_at` | فتحات الصفحة (مش التحديث كل 5 ثواني)، والإيقاف |

### 3.16 `ride_safety_incidents` — SOS (اتعمل 2026-10-14)
| العمود | الوصف |
|---|---|
| `uuid`، و`ride_id` (cascade)، و`type` (`sos`) | |
| `reporter_type` (`user` / `driver`)، و`reporter_id` | |
| `lat`، و`lng`، و`accuracy_m`، و`ride_status`، و`note` | المكان وحالة الرحلة لحظة الضغط |
| `status` (`open` / `acknowledged` / `resolved`)، و`handled_by_admin_id` (nullOnDelete)، و`acknowledged_at`، و`resolved_at`، و`resolution_note` | المعالجة؛ `index(status, created_at)` |

### 3.17 `ride_trusted_contacts` — الجهات الموثوقة (اتعمل 2026-10-14)
| العمود | الوصف |
|---|---|
| `owner_type` (`user` / `driver`)، و`owner_id` | صاحبها؛ `index(owner_type, owner_id)`، وحد أقصى `safety.max_contacts` |
| `name`، و`phone` | |
| `share_mode` (`all` / `night` / `manual`)، و`notify_arrival` | إمتى يوصله رابط الرحلة لوحده، و«وصلت بالسلامة» |

### 3.18 `ride_safety_preferences` — تفضيلات السلامة (اتعمل 2026-10-14)
| العمود | الوصف |
|---|---|
| `owner_type`، و`owner_id` | unique |
| `pin_mode` (`policy` / `always` / `night`) | رمز الرحلة حسب المدينة أو اختيار الراكب |
| `checkin_after_min` | الاطمئنان بعد الوصول (null = مقفول) |
| `seatbelt_reminder` | |

### 3.19 `ride_safety_checks` — «هل أنت بخير؟» (اتعمل 2026-10-14)
| العمود | الوصف |
|---|---|
| `uuid`، و`ride_id` (cascade) | |
| `kind` (`ride_check` / `checkin`)، و`reason` (`long_stop` / `deviation` / `scheduled`) | |
| `target_type`، و`target_id` | مين بيتسأل |
| `due_at`، و`asked_at`، و`answered_at`، و`answer` (`ok` / `help`)، و`escalated_at` | لو مفيش رد في `safety.check_answer_s` → حادثة وتنبيه للجهات الموثوقة |
| `meta` | المكان، ومدة الوقوف أو البعد عن المسار؛ `index(ride_id, kind)`، و`index(due_at, asked_at)` |

---

## 4. الفلوس بعد الرحلة

### 4.1 `ride_settlement_items`
كل بند فلوس ليه علاقة بالسواق في رحلة. ده مصدر «الرصيد المعلّق» و«مركز حماية الأرباح» (D-209، D-404).

| العمود | النوع | القيد | الوصف |
|---|---|---|---|
| `id` | `bigIncrements` | PK | |
| `uuid` | `uuid` | unique | |
| `ride_id` | `foreignId → rides` | | |
| `driver_id` | `foreignId → drivers` | | |
| `type` | `enum SettlementItemType` | | trip_earning / commission / tax / tip / discount_compensation / cancel_compensation / debt_collected / adjustment |
| `amount_minor` | `bigint` | | بإشارة: الموجب للسواق، والسالب عليه |
| `currency_id` | `foreignId → currencies` | | |
| `status` | `enum SettlementStatus` | | pending / posted / held / reversed |
| `release_at` | `timestamp` | nullable | إمتى يبقى متاح (`settlement.pending_days`) |
| `posted_at` | `timestamp` | nullable | |
| `wallet_transaction_id` | `foreignId → wallet_transactions` | nullable | الحركة في المحفظة |
| `financial_entry_id` | `foreignId → financial_entries` | nullable | القيد في دفتر المنصة |
| `reason_key` | `string(64)` | nullable | سبب الحجز أو العكس، وبيظهر للسواق |
| timestamps | | | `updated_at` بيتغير مع الحالة بس |

- **الكاش:**
  - العمولة والضريبة بيبقوا `posted` على طول، وبيتخصموا من محفظة السواق.
  - لو السواق حصّل دين قديم على الراكب، بيتسجل `debt_collected` (خصم من السواق) وفي نفس الوقت بيتضاف المبلغ لمحفظة الراكب (زي Jawad).
- **الدفع الإلكتروني:** صافي الرحلة بيفضل `pending` لحد `release_at`، وبعدين أمر بيحوّله `posted` ويضيفه لمحفظته.
- **النزاع:** بيحوّل البند لـ `held` لحد القرار.
- **فهارس:** `index(driver_id, status)`، و`index(status, release_at)`، و`index(ride_id)`.

---

## 5. السواق في الرحلات (`Modules/Rides`)

### 5.1 `driver_ride_profiles`
صف لكل سواق، فيه أرقامه في خدمة الركاب.

| العمود | النوع | الوصف |
|---|---|---|
| `driver_id` | PK | |
| `tier` | `string(16)` | new / bronze / silver / gold / diamond |
| `rating_avg` | `decimal(3,2)` | |
| `rating_count` | `unsignedInteger` | |
| `trips_count` | `unsignedInteger` | |
| `acceptance_bp` / `cancel_bp` / `completion_bp` | `unsignedInteger` | على آخر 30 يوم |
| `points_balance` | `integer` | |
| `last_trip_at` | `timestamp` nullable | |
| `updated_at` | `timestamp` | |

### 5.2 `driver_category_eligibility`
| العمود | الوصف |
|---|---|
| `driver_id`، و`category_id`، و`status` (eligible / ineligible / suspended / pending)، و`reason_code`، و`checked_at`، و`admin_override` (bool)، و`decided_by_admin_id` | `unique(driver_id, category_id)` |

- الأهلية بتتحسب أوتوماتيك من المستندات والمركبة والرتبة والجنس، والإدارة تقدر تتجاوز الحساب ده (D-10، D-17).

### 5.3 `driver_preferences`
| العمود | النوع | الوصف |
|---|---|---|
| `driver_id` | PK | |
| `categories` | `json` | الفئات اللي مفعّلها، من اللي مؤهل ليها |
| `pickup_radius_m` | `unsignedInteger` nullable | (D-33) |
| `destination` | `json` nullable | `{lat, lng, expires_at}` (D-38) |
| `auto_accept` | `json` nullable | التشغيل والفلاتر (D-34..37) |
| `navigation_app` | `string(16)` | google_maps / waze / in_app |
| `sounds` | `json` | |
| `voice_readout` | `boolean` | |
| `hide_earnings` | `boolean` | |
| `daily_goal_minor` | `bigint` nullable | |
| `updated_at` | `timestamp` | |

### 5.4 `driver_daily_stats`
| العمود | النوع | الوصف |
|---|---|---|
| `id`، و`driver_id`، و`stat_date` (`date` بتوقيت مدينته) | | `unique(driver_id, stat_date)` |
| `online_s`، و`trips`، و`offers_received`، و`offers_accepted`، و`offers_declined`، و`cancels` | `unsignedInteger` | |
| `gross_minor`، و`net_minor`، و`tips_minor`، و`bonus_minor`، و`commission_minor`، و`tax_minor` | `bigint` | |
| `distance_with_rider_m`، و`distance_empty_m` | `unsignedInteger` | |
| `points` | `integer` | |
| `updated_at` | `timestamp` | |

- **التحديث:** بيزيد مع كل حدث (قبول، إنهاء، تسوية).
- **المراجعة:** أمر بالليل بيعيد حساب اليوم من المصادر، عشان لو حصل خطأ.

### 5.5 `driver_points_ledger` (بيتضاف بس)
| العمود | الوصف |
|---|---|
| `id`، و`driver_id`، و`points` (بإشارة)، و`balance_after`، و`event`، و`rule_id` (nullable)، و`ride_id` (nullable)، و`note`، و`created_at` | `index(driver_id, id)` |

---

## 6. الراكب

### 6.1 `rider_ride_profiles`
| العمود | الوصف |
|---|---|
| `user_id` (PK)، و`rating_avg`، و`rating_count`، و`trips_count`، و`cancels_count`، و`no_show_count`، و`last_trip_at`، و`updated_at` | `rating_avg` هو `customerRating` اللي بيروح لوصل |

### 6.2 `ride_blocks`
| العمود | الوصف |
|---|---|
| `id`، و`blocker_type` (user / driver)، و`blocker_id`، و`blocked_type`، و`blocked_id`، و`ride_id` (nullable)، و`reason`، و`created_at` | `unique(blocker_type, blocker_id, blocked_type, blocked_id)` |

- التوزيع بيتشيك على الحظر في الاتجاهين.

### 6.3 `favorite_drivers`
| العمود | الوصف |
|---|---|
| `id`، و`user_id`، و`driver_id`، و`created_at` | `unique(user_id, driver_id)`. لدائرة المفضلين (المرحلة 7) |

- **الأماكن المحفوظة:** في `user_addresses` الموجود (home / work / other)، فمفيش جدول جديد.

---

## 7. وصل

### 7.1 `driver_regulator_status`
| العمود | النوع | الوصف |
|---|---|---|
| `id` | `bigIncrements` | |
| `driver_id` | `foreignId → drivers` | |
| `vehicle_id` | `foreignId → vehicles` nullable | المركبة اللي اتفحصت |
| `regulator` | `string(16)` | `wasl` |
| `country_id` | `foreignId → countries` | |
| `driver_status` / `vehicle_status` | `string(24)` | كلمة وصل زي ما رجعت بحروف صغيرة (valid / invalid / pending / rejected / expired …). `valid` بس هي اللي بتخلي السواق يشتغل |
| `driver_expires_at` / `vehicle_expires_at` | `date` nullable | |
| `vehicle_expires_at_hijri` | `string(16)` nullable | انتهاء رخصة السير بالهجري زي ما وصل بعته (جواد: `license_expiry_date_hijri`) |
| `criminal_record_status` | `string(32)` nullable | |
| `rejection_codes` | `json` nullable | أسباب رفض السواق |
| `vehicle_rejection_codes` | `json` nullable | أسباب رفض المركبة لوحدها (جواد: `car_infos.rejection_reasons`) |
| `blocked_until` | `date` nullable | بعد رفض الصحيفة الجنائية (11 شهر) |
| `registration_attempts` | `unsignedSmallInteger` | |
| `last_registered_at` | `timestamp` nullable | آخر محاولة تسجيل (جواد: `wasl_last_registration_attempt`) |
| `last_checked_at` / `next_check_at` | `timestamp` nullable | |
| timestamps | | |

- **unique:** `(driver_id, regulator)`.
- **قاعدة التوزيع (§11.4 في الخطة):** في دولة فيها جهة رقابية، السواق مؤهل بس لو التلات شروط دول اتحققوا:
  - `driver_status = valid` و`vehicle_status = valid`.
  - التاريخين لسه مخلصوش.
  - `vehicle_id` هي المركبة النشطة.

### 7.2 `ride_regulator_cities`
الاسم الرسمي لكل مدينة عند الجهة الرقابية، **بالحرف**. وصل بيرفض الرحلة لو الاسم فيه حرف غلط.

| العمود | النوع | الوصف |
|---|---|---|
| `id` | `bigIncrements` | |
| `regulator` | `string(16)` | `wasl` |
| `country_id` | `foreignId → countries` | |
| `city_id` | `unsignedBigInteger` nullable | مدينتنا (§0.4). `null` = اسم في القايمة الرسمية لسه متربطش بمدينة |
| `official_name` | `string(80)` | الاسم زي ما الجهة بتقبله بالظبط. مبيتعدلش بعد أول رحلة اتقبلت بيه |
| `accepted_at` | `timestamp` nullable | أول رحلة وصل قبلها بالاسم ده (يعني الاسم اتأكد) |
| `last_rejected_at` | `timestamp` nullable | آخر رفض بسبب الاسم |
| `status` | `boolean` | |
| timestamps | | |

- **unique:** `(regulator, official_name)` و`(regulator, city_id)`.
- **القواعد:**
  - الاسم مبيتكتبش في الكود ولا بيتاخد من الترجمة ولا من Google.
  - الإدارة بتختار الاسم من القايمة الرسمية، وبعد أول رحلة تتقبل بيه بيتقفل (`accepted_at`).
  - المدينة اللي ملهاش اسم: الرحلة **مبتتبعتش** (`wasl_status = failed`، والكود `missing_city`)، وبتظهر في اللوحة في «رحلات مستنية تتبعت لوصل»، ولما الاسم يتسجل بتتبعت تاني.
  - الـ seed فيه «الرياض» بس، لأنه الاسم اللي Jawad شغال بيه في السوق.

### 7.3 `ride_settings` (صف واحد، من لوحة التحكم)
| العمود | الوصف |
|---|---|
| `google_maps_key` | مفتاح Google للسيرفر، **مشفّر**، ومبيرجعش في أي رد (بيظهر آخر 4 حروف بس). لو فاضي: `GOOGLE_MAPS_SERVER_KEY` |
| `fallback_speed_kmh` | السرعة المتوسطة للحساب المجاني لما Google ميردش (60 زي Jawad) |
| `fallback_road_factor_bp` | معامل الطريق على الخط المستقيم (13000 = ×1.3) |
| `geocode_cache_days` | مدة حفظ اسم المدينة لكل نقطة (30) |

### 7.4 `wasl_logs` (بيتضاف بس)
| العمود | الوصف |
|---|---|
| `id`، و`subject_type` / `subject_id` (driver / ride)، و`type` (driver_registration / eligibility / trip / locations)، و`request` (json)، و`response` (json)، و`status_code`، و`success`، و`result_code`، و`duration_ms`، و`created_at` | `index(subject_type, subject_id)`، و`index(type, created_at)` |

- **ممنوع** يتخزن فيه `app_key` أو أي سر.

---

## 8. التقييم
- **في جدول `ratings` الموجود:**
  - التقييم بيبقى على **الرحلة**: `rateable` = `ride` (alias جديد في `Rating::RATEABLES`).
  - `type` = `driver` (الراكب بيقيّم السواق) أو `rider` (السواق بيقيّم الراكب).
  - `author` = `user` أو `driver`.
  - كده `unique_key` (author + rateable) معناه تقييم واحد لكل طرف في كل رحلة.
- **الوسوم:** جدول جديد `ride_rating_tag_selections` (`rating_id`، و`tag_id`، و`unique` على الاتنين).
- **المتوسطات:** بتتحدث في `driver_ride_profiles` و`rider_ride_profiles` مع كل تقييم.

---

## 9. سجل التدقيق: `audit_logs` (جديد، مشترك)
مفيش سجل تدقيق عام في دُرر دلوقتي، والسواق والرحلات محتاجينه (R-195، D-351).

| العمود | الوصف |
|---|---|
| `id`، و`actor_type` / `actor_id` (admin / system)، و`action` (`ride_policy.updated`، و`driver.approved`…)، و`subject_type` / `subject_id`، و`before` (json)، و`after` (json)، و`ip`، و`created_at` | بيتضاف بس. `index(subject_type, subject_id)`، و`index(actor_type, actor_id)` |

- **مكانه** في `app/` (General)، عشان أي موديول بعد كده يقدر يستخدمه.

---

## 10. تعديلات في موديولات موجودة
| المكان | التعديل | المرحلة |
|---|---|---|
| `config/auth.php` | حارس `driver_api` + provider اسمه `drivers` | 0 |
| `Modules/Wallet/config/config.php` | `morph_map`: `'driver' => Driver::class` | 0 |
| `config/referral.php` | `morph_map`: `driver` | 0 |
| `BroadcastServiceProvider` + `routes/channels.php` | `driver_api` في `Broadcast::routes`، وقناة `Modules.Driver.Models.Driver.{id}` | 0 |
| أجهزة الإشعارات | alias `driver`، وموديول السواق هو اللي بيسجله | 0 |
| `WalletTransactionType` | أنواع جديدة: `commission`، و`tax`، و`tip`، و`discount_compensation`، و`debt_settlement` | 1 |
| `financial_categories` (seeder) | `trip_commissions` (income)، و`trip_taxes` بفئة لوحدها اسمها «ضريبة التوصيل»، و`discount_expenses` (expense) | 1 |
| `PaymentCompletionService` | Event بعد ما الشحن يكتمل (`TopupCompleted`)، والرحلات بتسمعه عشان تعمل الـ Hold. نفس فكرة `settleFromTopup` بتاعة الـ Checkout | 1 |
| `Modules/Chat/config/config.php` | `driver` في `participants` و`enabled_participants` | 1 |
| `chat_conversations` | `type = ride`، وأعمدة `context_type` و`context_id` و`closed_at`. التفاصيل تحت الجدول | 1 |
| `Rating::RATEABLES` | `ride` | 1 |
| `support_tickets` | صاحب التذكرة يبقى polymorphic بدل `user_id`، بـ migration بتحوّل التذاكر الموجودة لـ `user` | 3 |

**شات الرحلة (`chat_conversations`، قرار المالك 2026-10-13):**
- محادثات الرحلات مبتظهرش في قايمة الشات العادية.
- **أول ما الرحلة تخلص أو تتلغي، التواصل بين السواق والراكب بيقف خالص:** مفيش رسايل ولا مكالمات بعدها.
- **المحادثة بتفضل محفوظة** وتتقري بس، والإدارة تراجعها من لوحة التحكم لو فيه مشكلة أو شكوى.
- المكالمة مسموحة بس والرحلة نشطة.

---

## 11. الـ Enums
| الـ Enum | القيم |
|---|---|
| `DriverStatus` | pending_documents / under_review / active / suspended / restricted / blocked |
| `DocumentStatus` | pending / approved / rejected / expired / replaced |
| `VehicleStatus` | pending / approved / rejected / inactive |
| `VehicleBodyType` | sedan / suv / van / minibus / bus / motorcycle / boat / yacht |
| `EligibilityStatus` | eligible / ineligible / suspended / pending |
| `PresenceStatus` | offline / online / paused / on_trip |
| `PricingStrategy` | distance / trip_flat / bid / seat / time |
| `PricingMode` | upfront / metered |
| `BookingMode` | instant / scheduled / bid |
| `RideStatus` | awaiting_payment / scheduled / searching / bidding / accepted / arrived / in_progress / completed / canceled / no_show / expired |
| `RidePaymentMethod` | wallet / card / cash |
| `RidePaymentStatus` | pending / awaiting_topup / held / captured / released / cash_due / cash_collected / failed |
| `CancelActor` | rider / driver / platform / system |
| `CancelStage` | before_accept / after_accept / after_arrival / in_progress |
| `OfferStatus` | sent / accepted / declined / expired / withdrawn |
| `BidType` | accept_rider_price / counter |
| `BidStatus` | queued / pending / accepted / declined / expired / withdrawn |
| `ChangeRequestType` | destination / add_stop / remove_stop / extension |
| `ChangeRequestStatus` | pending / applied / rejected / expired / canceled |
| `DistanceSource` | device_odometer / server_track / route_estimate |
| `ZoneType` | service_area / blocked / airport / surcharge / pickup_only |
| `TaxBase` | commission / fare / fare_and_fees |
| `TaxBearer` | driver / rider |
| `SettlementItemType` | trip_earning / commission / tax / tip / discount_compensation / cancel_compensation / debt_collected / adjustment |
| `SettlementStatus` | pending / posted / held / reversed |
| `RegulatorStatus` | valid / invalid / pending / rejected |
| `WaslTripStatus` | pending / sent / failed / not_required |
| `PriceLineCode` | fare / zone_surcharge / weather_surcharge / surge / extra.child_seat / extra.pet / extra.priority / extra.airport / stops_wait / waiting / discount / customer_fee / payment_method_fee / fx_fee / tax / previous_balance / tip / total / commission / driver_net |
| `RideEventType` | created / quote_confirmed / payment_held / payment_failed / dispatch_round / offer_sent / offer_declined / offer_expired / bid_submitted / bid_declined / bid_expired / price_raised / accepted / driver_released / rider_changed_driver / arrived / pin_failed / started / stop_arrived / stop_departed / change_requested / change_applied / change_rejected / gps_off / gps_on / completed / canceled / no_show / expired / payment_captured / payment_released / cash_collected / change_to_wallet / rated / wasl_sent / wasl_failed |

- **الإلغاء:** الخطة كانت كاتبة `canceled_by_rider` و`canceled_by_platform`. هنا هي حالة واحدة `canceled`، ومين لغى في `cancel_actor`.

### الانتقالات المسموحة بين الحالات
```
awaiting_payment → searching | bidding | scheduled | expired | canceled
scheduled        → searching | bidding | canceled
searching        → accepted | expired | canceled
bidding          → accepted | expired | canceled
accepted         → arrived | searching (السواق اعتذر بسبب مسموح، أو الراكب غيّر السواق) | canceled
arrived          → in_progress | no_show | canceled
in_progress      → completed | canceled (طارئ بس، بموافقة السياسة)
completed / canceled / no_show / expired → (حالات نهائية)
```

---

## 12. مفاتيح `ride_policies.data` والقيم الافتراضية
> **مصادر القيم:**
> - «Jawad»: مأخوذة منه.
> - «Wireflow»: أمثلة من شاشات العميل.
> - «قرار المالك»: اتحددت في المحادثة.
> - «افتراض»: لحد ما الإدارة تحدده.

| المفتاح | الافتراضي | المصدر |
|---|---|---|
| `pricing_mode` | `upfront` | قرار المالك (اختيار من اللوحة) |
| `quote_ttl_s` | 300 | افتراض |
| `material_change_bp` | 1000 (10%) | افتراض |
| `price.base_minor` / `price.per_km_minor` / `price.per_min_minor` / `price.min_fare_minor` | حسب الفئة والمدينة | Jawad (المعادلة) |
| `price.commission_type` / `price.commission_value` | percent / 2000 (20%) | Jawad (النوع)، والنسبة افتراض |
| `price.cancel_fee_minor` | حسب الفئة | Jawad |
| `waiting.free_s` | 180 | Wireflow («انتظار مجاني 3 دق») |
| `price.waiting_per_min_minor` | 50 | Wireflow («0.5 ر.س/دقيقة») |
| `stops.max` | 3 | افتراض |
| `stops.free_wait_s` / `price.stop_wait_per_min_minor` | 120 / 50 | افتراض |
| `open_destination.hold_minor` | 10000 | افتراض |
| `search.min_radius_m` / `search.radius_step_m` / `search.max_radius_m` | 5000 / 5000 / 50000 | Jawad |
| `search.round_interval_s` | 10 | Jawad |
| `search.offer_ttl_s` | 15 | افتراض (`ride_accept_decline_duration` في Jawad) |
| `search.ask_rider_after_s` | 240 | Jawad (4 دقايق) |
| `search.max_search_s` | 600 | Jawad (10 دقايق) |
| `search.refuse_cooldown_s` | 300 | Jawad |
| `driver.stale_after_s` | 900 | Jawad (15 دقيقة) |
| `driver.min_balance_minor` | −5000 | Jawad (`driver_min_to_get_ride`) |
| `arrival.radius_m` | 100 | Jawad |
| `cancel.rider_daily_free` / `cancel.driver_daily_free` | 3 / 3 | Jawad |
| `cancel.free_after_accept_s` | 120 | افتراض |
| `no_show.wait_s` | 300 | افتراض |
| `pin.required` | false | افتراض |
| `scheduled.lead_time_s` / `scheduled.free_cancel_before_s` | 1800 / 3600 | R-106 (60 دقيقة) |
| `bids.rider_max_discount_bp` | 2000 | قرار المالك (20%) |
| `bids.counter_cap_bp` | 5000 | افتراض |
| `bids.counter_steps_minor` | [500, 1000, 1500] | قرار المالك (+5 / +10 / +15) |
| `bids.ttl_s` | 20 | قرار المالك |
| `bids.max_visible` / `bids.max_attempts_per_driver` | 0 / 0 (من غير حد) | قرار المالك |
| `bids.batch_size` / `bids.batch_interval_s` | 3 / 10 | D-86، والفترة افتراض |
| `bids.suggest_raise_after_s` | 60 | افتراض |
| `location.idle_interval_s` / `location.trip_send_interval_s` / `location.trip_store_interval_s` | 30 / 5 / 15 | قرار المالك (30)، والباقي افتراض |
| `location.retention_days` | 30 | افتراض |
| `gps_gap.strategy` / `gps_gap.straight_factor_bp` | route / 13000 | افتراض |
| `cash.allowed` / `cash.rider_max_debt_minor` | true / 0 | Jawad (`max_debt_for_cash`) |
| `settlement.pending_days` | 2 | افتراض |
| `detour.enabled` / `detour.tolerance_bp` | false / 2000 | افتراض |

---

## 13. العلاقات
```
drivers 1─* driver_documents *─1 driver_document_types 1─* driver_document_requirements
drivers 1─* vehicles *─1 vehicle_models *─1 vehicle_makes ; vehicles *─1 vehicle_colors
drivers 1─1 driver_presence ; drivers 1─* driver_presence_sessions
drivers 1─* driver_location_batches 1─* driver_location_log
drivers 1─* driver_service_eligibility *─1 service_categories
drivers 1─1 driver_ride_profiles ; 1─1 driver_preferences ; 1─* driver_daily_stats ; 1─* driver_points_ledger
drivers 1─* driver_category_eligibility *─1 ride_categories ; drivers 1─1 driver_regulator_status (لكل جهة)
users 1─* ride_quotes ; users 1─* rides ; users 1─1 rider_ride_profiles
rides *─1 ride_categories ; rides *─1 drivers ; rides *─1 vehicles ; rides *─1 ride_quotes
rides 1─* ride_stops / ride_events / ride_offers / ride_bids / ride_price_offers / ride_change_requests
rides 1─* ride_price_lines / ride_adjustments / ride_holds (*─1 wallet_holds) / ride_settlement_items
rides 1─1 ride_tracks ; rides 1─* ratings (rateable = ride)
ride_policies → countries / (city) / ride_zones / ride_categories ; ride_tax_rules → countries
```

---

## 14. الحجم والاحتفاظ
**التقدير على أساس:** 1000 سواق، كل واحد 10 ساعات Online، منهم 2 ساعة رحلات في اليوم.

| الجدول | في اليوم | الاحتفاظ |
|---|---|---|
| `driver_location_log` | نقطة كل 30 ثانية والسواق فاضي (≈ 960 ألف) + نقطة كل 15 ثانية في الرحلة (≈ 480 ألف) ≈ **1.4 مليون صف** | 30 يوم ≈ 43 مليون صف. بعدها المسار المضغوط بيفضل في `ride_tracks` |
| `ride_events` | حوالي 20–30 حدث لكل رحلة | دايم |
| `ride_offers` / `ride_bids` | حسب عدد الطلبات | دايم، للتحليل والإحصائيات. وممكن أرشفة بعد سنة |
| `ride_quotes` | من 3 لـ 5 لكل رحلة | 7 أيام، للي مطلعش منه رحلة |
| `wasl_logs` | طلب لكل رحلة + المواقع كل دقيقة | 90 يوم (افتراض) |

- **`driver_location_log` هو الجدول التقيل.** لو العدد كبر: Partition بالأيام على MySQL، في migration لوحدها جوه شرط MySQL، ومن غير أي تغيير في الكود.

---

## 15. جداول المراحل الجاية (هتتفصّل في وقتها)
| المرحلة | الجداول |
|---|---|
| 3 | `ride_shares` (رابط المشاركة)، و`ride_sos_alerts`، و`ride_disputes`، و`ride_lost_items`، و`trusted_contacts` |
| 6 | الباصات (`bus_trips`، و`bus_seats`، و`seat_holds`، و`tickets`)، ومخزون الدراجات والمراكب |
| 7 | حسابات العائلة واليافعين، ودائرة المفضلين (`favorite_drivers` موجود هنا من المرحلة 1 كجدول بس) |

---

## 16. ترتيب الـ Migrations

**المرحلة 0:**
1. `Modules/Driver`، بالترتيب ده:
   - `drivers`.
   - `driver_document_types` (+ترجمة)، و`driver_document_requirements`.
   - `vehicle_makes`، و`vehicle_models`، و`vehicle_colors` (+ترجمات)، و`vehicles`.
   - `driver_documents`.
   - `driver_service_eligibility`.
   - `driver_presence`، و`driver_presence_sessions`.
   - `driver_location_batches`، و`driver_location_log`.
2. `app/`: `audit_logs`.
3. `Modules/Rides`:
   - `ride_categories` (+ترجمة)، و`ride_category_document_types`.
   - `ride_feature_rules`.
   - `ride_zones` (+ترجمة)، و`ride_policies`.
   - `ride_tax_rules` (+ترجمة).
   - `ride_cancel_reasons`، و`ride_rating_tags` (+ترجمات).
   - `driver_points_rules`.
4. **Seeders:**
   - الفئات الـ11، والاقتصادية بس اللي مفعّلة.
   - سياسة السعودية الافتراضية، وضريبة السعودية.
   - أنواع المستندات للسعودية.
   - ماركات وموديلات وألوان أساسية.

**المرحلة 1:**
- **الرحلة:** `ride_quotes`، و`rides`، و`ride_counters`، و`ride_holds`، و`ride_stops`، و`ride_events`، و`ride_active_locks`، و`ride_offers`، و`ride_price_lines`، و`ride_adjustments`، و`ride_tracks`.
- **الفلوس:** `ride_settlement_items`.
- **السواق:** `driver_ride_profiles`، و`driver_category_eligibility`، و`driver_preferences`، و`driver_daily_stats`، و`driver_points_ledger`.
- **الراكب:** `rider_ride_profiles`، و`ride_blocks`، و`favorite_drivers`.
- **وصل:** `driver_regulator_status`، و`wasl_logs`.
- **التقييم:** `ride_rating_tag_selections`.
- **تعديلات §10** اللي مرحلتها 1.

**المرحلة 2:** `ride_bids`، و`ride_price_offers`.

**المرحلة 3:** `ride_change_requests`، و`ride_pickup_points` (+ترجمة)، وتعديل `support_tickets`، وجداول §15.

---

## 17. قرارات محتاجة موافقة قبل التنفيذ
| # | القرار | اقتراحي |
|---|---|---|
| 1 | سجل المدن: نستخدم `discover_cities` زي الشات (من غير FK)، ولا نعمل جدول مدن عام في General؟ | `discover_cities` دلوقتي، زي الشات |
| 2 | الأماكن: `lat` و`lng` والحساب في PHP (بيشتغل على SQLite وMySQL)، و`SPATIAL` بعدين لو احتجنا | زي §0.3 |
| 3 | السياسات والأسعار في JSON واحد بنطاقات (§2.4)، والتاريخ في سجل التدقيق | أيوه |
| 4 | سجل المواقع: 30 يوم، والمسار المضغوط بيفضل على طول | أيوه |
| 5 | شات الرحلة جوه موديول الشات بنوع `ride` (تعديل في `chat_conversations`) | **اتقرر:** بيتقفل أول ما الرحلة تخلص، ويفضل محفوظ للإدارة |
| 6 | التقييم على الرحلة نفسها (`rateable = ride`) | أيوه |
| 7 | الضريبة المحصّلة في الدفتر | **اتقرر:** فئة لوحدها «ضريبة التوصيل» (`trip_taxes`) |
| 12 | القايمة الرسمية لأسماء المدن في وصل | محتاجينها من ELM/وصل. لحد ما توصل، «الرياض» بس |
| 8 | رقم الرحلة `DR-YYMMDD-NNNN` (زي الـ Wireflow) | أيوه |
| 9 | الرصيد المعلّق للسواق في `ride_settlement_items`، مش bucket جديد في المحفظة | أيوه |
| 10 | سجل تدقيق عام `audit_logs` في `app/` | أيوه |
| 11 | الفاتورة الإلكترونية (ZATCA) لعمولة دُرر ولفواتير الراكب (D-227) | مرحلة لاحقة، محتاجة قرار محاسبي |
