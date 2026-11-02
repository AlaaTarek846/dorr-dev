# الآيفون — الحسابات والإعدادات المطلوبة

الكود بتاع نسخة الآيفون خلص وبيتبني. الحاجات اللي هنا محتاجة حد عنده صلاحية على حساب Apple Developer وFirebase وOneSignal والسيرفر.
من غيرها التطبيق بيشتغل، بس فيه مزايا هتقف أو هتشتغل بطريقة أبسط من أندرويد (مكتوب جنب كل بند إيه اللي بيحصل لو اتساب).

**المعرّف الأساسي للتطبيق:** `com.dorr.app` (لو اتغير، اتغير في `iosApp/Config/Local.xcconfig` في `PRODUCT_BUNDLE_IDENTIFIER` و`DORR_APP_BUNDLE_ID` مع بعض، وكل المعرّفات اللي تحت بتمشي وراه).

---

## 1. حساب Apple Developer (مدفوع — 99$ في السنة)

- [ ] **الاشتراك نفسه** في Apple Developer Program، وهات الـ **Team ID** (10 حروف) من Membership details.
- [ ] **سجّل 4 App IDs** في Certificates, IDs & Profiles → Identifiers:

  | المعرّف | لإيه |
  |---|---|
  | `com.dorr.app` | التطبيق نفسه |
  | `com.dorr.app.broadcast` | مشاركة شاشة الموبايل كلها في المكالمة |
  | `com.dorr.app.notifications` | الإشعارات الخاصة («N رسايل جديدة»)، تسميات الذكاء على الموبايل، إخفاء الأكواد والفلوس |
  | `com.dorr.app.widgets` | ويدجت الرياضة «فريقي» و«لايف دلوقتي» |

- [ ] **فعّل Push Notifications** على `com.dorr.app`.
- [ ] **اعمل App Group** اسمه `group.com.dorr.app` وفعّل **App Groups** على الأربع معرّفات، واربطهم كلهم بنفس الجروب ده.
  - لو اتساب: مشاركة الشاشة بتشارك شاشة دور بس، الإشعارات بتظهر عادي من غير الخصوصية والتسميات، والويدجت بيفضل فاضي.
- [ ] **مفتاح APNs (.p8):** Keys → (+) → فعّل Apple Push Notifications service → نزّل الملف (**بيتنزل مرة واحدة بس، احفظه**) وخد الـ **Key ID**.
  - نفس المفتاح ده بيتحط في OneSignal وفي السيرفر (تحت).
- [ ] **حط الـ Team ID** في `iosApp/Config/Local.xcconfig`:
  ```
  DEVELOPMENT_TEAM = XXXXXXXXXX
  ```
- [ ] بعد كده Xcode بيعمل الـ Provisioning Profiles لوحده (Automatically manage signing).

## 2. OneSignal (نفس التطبيق اللي أندرويد عليه)

- [ ] في لوحة OneSignal → Settings → Platforms → **Apple iOS (APNs)**: ارفع ملف الـ `.p8` واكتب Key ID وTeam ID والـ Bundle ID `com.dorr.app`.
  - الـ App ID بتاع OneSignal الآيفون بياخده من السيرفر (`chat/realtime-config`)، يعني مفيش حاجة تتغير في الكود.
  - لو اتساب: **مفيش أي إشعارات توصل الآيفون** (شات، رحلات، رياضة، كله).

## 3. السيرفر (`.env`)

- [ ] حط مفاتيح رنين المكالمات وهو مقفول (VoIP / CallKit). الشرح بالعربي موجود في `.env.example`:
  ```
  APNS_KEY_ID=          # Key ID بتاع ملف .p8
  APNS_TEAM_ID=         # Team ID
  APNS_KEY=             # مسار ملف .p8 على السيرفر أو محتواه
  APNS_BUNDLE_ID=com.dorr.app
  APNS_PRODUCTION=false # true لما يبقى TestFlight / App Store
  APNS_TIMEOUT=10
  ```
  - لو اتساب: المكالمة بتوصل كإشعار عادي بدل ما الآيفون يرن بشاشة المكالمات بتاعته.
- [ ] شغّل الـ migrations الجديدة على السيرفر (`php artisan migrate`):
  - `database/migrations/2026_10_31_120000_add_voip_token_to_notification_devices.php`
  - `Modules/Rides/database/migrations/2026_10_29_120000_add_ios_map_provider_to_ride_settings.php`
- [ ] من لوحة الأدمن → إعدادات الرحلات → اختار **خريطة تطبيق الآيفون** (Google / Mapbox / Apple Maps).

## 4. Firebase (تقارير الكراش — Crashlytics)

- [ ] في **نفس مشروع Firebase** بتاع أندرويد: Add app → iOS → Bundle ID `com.dorr.app`.
- [ ] نزّل `GoogleService-Info.plist` وحطه في `iosApp/Dorr/GoogleService-Info.plist`، وبعدين `cd iosApp && xcodegen`.
  - لو اتساب: التطبيق شغال عادي بس مفيش تقارير كراش.

## 5. Google Maps (لو الأدمن اختار Google للآيفون)

- [ ] في Google Cloud (نفس مشروع مفتاح أندرويد): اعمل API key مقفول على **iOS apps** بالـ Bundle ID `com.dorr.app`، وفعّل **Maps SDK for iOS**.
- [ ] حطه في `iosApp/Config/Local.xcconfig`:
  ```
  DORR_GOOGLE_MAPS_KEY = AIza...
  ```
  - لو اتساب: الآيفون بيرسم بـ Apple Maps بدل خريطة فاضية.
- Mapbox مش محتاج حاجة على الآيفون — التوكن بييجي من السيرفر زي أندرويد.

## 6. النشر (لما تيجي ترفع)

- [ ] App Store Connect → اعمل التطبيق بالـ Bundle ID `com.dorr.app`.
- [ ] غيّر `aps-environment` في `iosApp/Dorr/Dorr.entitlements` من `development` لـ `production`، و`APNS_PRODUCTION=true` على السيرفر.
- [ ] جهّز **Privacy Policy URL** وبيانات App Privacy (التطبيق بيطلب: الكاميرا، المايك، جهات الاتصال، الموقع، الصور، Face ID).
- [ ] في مراجعة Apple: اكتب إن التطبيق بيستخدم **VoIP push / CallKit** للمكالمات، و**الموقع في الخلفية** للموقع المباشر والرحلات، و**الصوت في الخلفية** للمكالمات وغرف الصوت.

---

## حاجات مختلفة في الآيفون بطبيعتها (مش محتاجة اشتراك)

دي مش نواقص، Apple مش بتسمح بيها، والتطبيق بيعمل البديل:

- **نغمة إشعار لكل شات:** Apple مش بتسمح لتطبيق يختار من نغمات الموبايل.
- **إشعار شغال طول ما المكالمة / غرفة الصوت / الموقع المباشر شغالين:** الآيفون بيكمّلهم في الخلفية من غير إشعار.
- **صلاحية «إظهار المكالمات فوق التطبيقات»:** الآيفون بيرن بشاشة المكالمات بتاعته (CallKit) ومش محتاج الصلاحية دي.
- **قنوات الإشعارات:** مش موجودة في الآيفون.
- **إخفاء إشعار شات «مخفي» بالكامل:** محتاج صلاحية خاصة من Apple (Notification Filtering) بتتطلب بفورم. من غيرها بيظهر كـ «N رسايل جديدة» من غير أي تفاصيل. لو عايز تطلبها: developer.apple.com/contact/request/notification-service.
