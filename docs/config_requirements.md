# متطلبات الإعداد — كل اللي محتاجين نجيبه من برا (Config Requirements)

> المرجع الوحيد لكل **حساب أو مفتاح أو خدمة خارجية** محتاجها دُر عشان تشتغل.
> لكل بند: بيعمل إيه، بيتحط فين، حالته على جهاز التطوير الحالي، ولو بيشتغل على **استضافة مشتركة (Shared Hosting)** ولا محتاج **VPS**.
>
> آخر مراجعة: 2026-10-04. أي مفتاح بيتغير في `.env` لازم بعده **`php artisan config:cache`** (السبب في [14-DEPLOYMENT.md](14-DEPLOYMENT.md)).

## ملخص سريع

| # | الخدمة | بتستخدم في | بتتحط في | الحالة دلوقتي | استضافة مشتركة؟ |
|---|---|---|---|---|---|
| 1 | **Pusher Channels** | الرسائل اللحظية: "بيكتب"، و"متصل"، والعلامات، والمكالمة الواردة والتطبيق مفتوح | `.env` | ❌ **مقفول** (`BROADCAST_CONNECTION=log`) | ✅ |
| 2 | **OneSignal** (ومعاه Firebase) | الإشعارات على الموبايل، ورنة المكالمة والتطبيق مقفول | `.env` | ❌ **مش متضبط** | ✅ |
| 3 | **LiveKit Cloud** | مكالمات الصوت والفيديو | `.env` | ❌ **مش متضبط** (السيرفر بيرد 503) | ✅ (Cloud) / ❌ (لو استضفناه بنفسنا) |
| 4 | **Giphy** | الصور المتحركة ومكتبة الملصقات | `.env` | ❌ **مش متضبط** | ✅ |
| 5 | **SMS** (Twilio / 4Jawaly / SMS Misr) | كود الدخول (OTP) | لوحة الأدمن ← SMS | ❌ مفيش حسابات | ✅ |
| 6 | **WhatsApp Cloud API** (Meta) | كود الدخول على واتساب | لوحة الأدمن ← WhatsApp | ❌ مفيش حسابات | ✅ |
| 7 | **SMTP / Mail** | إيميلات التحقق | `.env` | ✅ متضبط | ✅ |
| 8 | **Google Sign-In** | الدخول بجوجل | `.env` | ✅ متضبط | ✅ |
| 9 | **Apple Sign-In** | الدخول بأبل | `.env` | ❌ ناقص | ✅ |
| 10 | **مزودي الـ AI** (OpenAI / Anthropic / Google / Groq) | دُر AI، والـ AI جوه الشات (ترجمة، وفويس لنص، وتلخيص، وردود مقترحة) | لوحة الأدمن ← AI | ⚠️ 1 من 4 بس فيه مفتاح | ✅ |
| 11 | **MyFatoorah** | شحن المحفظة | `.env` | ✅ متضبط | ✅ |
| 12 | **ARB (البنك العربي)** | شحن المحفظة | `.env` | ❌ ناقص | ✅ |
| 13 | **URPay** | شحن المحفظة | `.env` | ❌ ناقص | ✅ |
| 14 | **تحديد الدولة بالـ IP** | اختيار الدولة تلقائياً في الدخول | مفيش مفتاح | ✅ (ipwho.is ثم ip-api.com مجاناً) | ✅ |
| 15 | **AWS S3** (اختياري) | تخزين الملفات بره السيرفر | `.env` | ➖ مش مستخدم (`FILESYSTEM_DISK=local`) | ✅ |

---

## 1. Pusher Channels: الرسائل اللحظية

- **بيعمل إيه:** يوصّل الأحداث فوراً: رسالة جديدة، و"بيكتب…"، و"متصل"، والعلامات الزرقا، والريأكشن، والمكالمة الواردة والتطبيق مفتوح. **من غيره الشات بيشتغل، بس الرسائل مش هتظهر غير لما تعمل refresh.**
- **منين:** [pusher.com](https://pusher.com) ← Channels ← Create app. فيه باقة مجانية بحدود اتصالات ورسائل يومية.
- **في `.env`:**
  ```
  BROADCAST_CONNECTION=pusher
  PUSHER_APP_ID=...
  PUSHER_APP_KEY=...
  PUSHER_APP_SECRET=...
  PUSHER_APP_CLUSTER=eu        # أو mt1/ap2… حسب اللي اخترته
  VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
  VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
  ```
  وبعد كده `npm run build` عشان الويب ياخد المفتاح.
- **التطبيق:** بياخد الإعداد من `GET chat/realtime-config` لوحده، ومفيش حاجة تتعمل في الأندرويد.
- **استضافة مشتركة:** ✅ لأن Pusher خدمة سحابية. البديل اللي بنستضيفه بنفسنا (**Reverb** أو **Soketi**) **محتاج VPS** لأنه process شغال طول الوقت. ده مكتوب كخطة مستقبلية في الذاكرة ("self-hosted realtime later").

## 2. OneSignal ومعاه Firebase: الإشعارات

- **بيعمل إيه:** إشعار الرسالة والتطبيق مقفول، وشاشة المكالمة الواردة كاملة، والمكالمة الفايتة، والإرسال الصامت. **من غيره مفيش إشعارات خالص.**
- **منين:**
  1. [onesignal.com](https://onesignal.com) ← New App ← Android (Google Android FCM).
  2. OneSignal بيطلب **Firebase**: [console.firebase.google.com](https://console.firebase.google.com) ← Project ← Project settings ← Service accounts ← Generate new private key، وترفع ملف JSON على OneSignal.
- **في `.env`:**
  ```
  ONESIGNAL_APP_ID=...
  ONESIGNAL_REST_API_KEY=...
  ```
- **التطبيق:** الـ App ID بيوصل للتطبيق من السيرفر (`realtime-config`)، ومحتاج تسجيل خروج ودخول أو تشغيل جديد عشان يسجّل الجهاز.
- **استضافة مشتركة:** ✅ لأن السيرفر بيبعت طلبات HTTP بس.

## 3. LiveKit Cloud: المكالمات

- **بيدعم إيه:** **صوت وفيديو الاتنين**، ومكالمات فردية وجماعية (ومعاهم مشاركة الشاشة كإمكانية في الخدمة). التطبيق مبني عليه فعلاً (`livekit-android`).
- **ليه بيشتغل على استضافة مشتركة:** السيرفر بتاعنا **مابيعديش الصوت ولا الفيديو**. كل اللي بيعمله إنه يطلّع **token** (JWT) بـ PHP عادي، والصوت والفيديو بيتنقلوا بين الموبايلات وخوادم LiveKit Cloud مباشرة. يعني **مش محتاج VPS**.
  - الحالة الوحيدة اللي محتاجة VPS: لو قررنا **نستضيف LiveKit Server بنفسنا** (محتاج منافذ UDP مفتوحة وprocess شغال طول الوقت).
- **منين:** [cloud.livekit.io](https://cloud.livekit.io) ← Create project ← Settings ← Keys. فيه باقة مجانية بحدود دقائق شهرية، والأسعار الحالية على موقعهم.
- **في `.env`:**
  ```
  LIVEKIT_URL=wss://<project>.livekit.cloud
  LIVEKIT_API_KEY=...
  LIVEKIT_API_SECRET=...
  ```
- **Agora:** **مش محتاجينه دلوقتي.** طلبناه كبديل لو LiveKit ماينفعش على الاستضافة المشتركة، وهو بينفع. لو قررنا بعدين نخلّي مزوّد المكالمات قابل للتغيير من **إعدادات الشات في الأدمن** (LiveKit أو Agora)، ده ممكن يتعمل. **NEEDS-DECISION.**
- **مهم:** المكالمات لازم تتفعّل لكل دولة على حدة بعد مراجعة قانونية، لأن فيه دول مكالمات الإنترنت فيها محتاجة ترخيص. ده بند في المرحلة التانية.

## 4. Giphy: الصور المتحركة والملصقات

- **منين:** [developers.giphy.com](https://developers.giphy.com) ← Create an App ← **API**. المفتاح ببلاش.
- **في `.env`:**
  ```
  GIPHY_API_KEY=...
  GIPHY_RATING=pg-13
  ```
- **ملاحظة:** حزم ملصقات دُر الخاصة **مش محتاجة Giphy**، بتترفع من لوحة الأدمن ← الشات ← الملصقات (PNG أو WebP شفاف، 512×512).

## 5 و6. SMS وWhatsApp: كود الدخول

- **بيتحطوا فين:** **من لوحة الأدمن** (موديول SMS)، مش من `.env`. كل مزوّد ليه حساب ودول بيشتغل فيها.
- **المزودين المدعومين في الكود:** Twilio، و4Jawaly (السعودية)، وSMS Misr (مصر)، وMeta WhatsApp Cloud API.
- **منين:**
  - **Twilio:** twilio.com ← Account SID وAuth Token ورقم إرسال.
  - **4Jawaly:** 4jawaly.com ← API key وsecret واسم مرسل معتمد.
  - **SMS Misr:** smsmisr.com ← username وpassword وsender.
  - **WhatsApp:** Meta for Developers ← WhatsApp ← Phone number ID وAccess token وقالب (template) معتمد للكود.
- **الوضع الحالي:** مفيش أي حساب. الدخول شغال على التطوير بكود ثابت (`1234`) جاي من `AUTH_PHONE_OTP_FIXED` في `config/auth_flow.php`، والقيمة الافتراضية `1234`. **لازم يتقفل قبل الإنتاج.**

## 7 و8 و9. البريد، وGoogle، وApple

- **SMTP:** `MAIL_*` في `.env`، ومتضبط.
- **Google:** `GOOGLE_CLIENT_ID` و`GOOGLE_CLIENT_SECRET` و`GOOGLE_REDIRECT_URI` من Google Cloud Console ← Credentials. متضبط.
- **Apple:** `APPLE_CLIENT_ID` و`APPLE_TEAM_ID` و`APPLE_KEY_ID` و`APPLE_PRIVATE_KEY` من Apple Developer (محتاج حساب مدفوع). **ناقص.**

## 10. مزودي الـ AI

- **بيتحطوا فين:** لوحة الأدمن ← AI ← المزودين (جدول `ai_providers`). فيه 4 مزودين متعرّفين ومفتاح واحد بس متحط.
- **منين:** OpenAI (platform.openai.com)، وAnthropic (console.anthropic.com)، وGoogle AI Studio، وGroq (console.groq.com).
- **الـ AI جوه الشات** بيستخدم نفس المزود النشط (الافتراضي). الترجمة والتلخيص والردود المقترحة شغالين مع أي مزود.
- **تحويل الفويس لنص محتاج مزود بياخد صوت:** OpenAI أو Groq (Whisper) أو Google (Gemini). Anthropic مابياخدش صوت، فلو هو النشط لازم يكون فيه واحد من التلاتة دول متفعّل ومعاه مفتاح، والسيرفر بيختاره لوحده. Groq عنده خطة مجانية، وده أرخص اختيار للفويس.
- **موديلات الفويس** (اختياري، في `.env`): `AI_OPENAI_TRANSCRIPTION_MODEL` (افتراضي `whisper-1`) و`AI_GROQ_TRANSCRIPTION_MODEL` (افتراضي `whisper-large-v3-turbo`)، و`AI_TRANSCRIPTION_TIMEOUT` (60 ثانية).
- **مفتاح التشغيل:** لوحة الأدمن ← إعدادات الشات ← "الذكاء الاصطناعي في الشات" (`ai_enabled`).

## 11 و12 و13. بوابات الدفع (المحفظة)

| البوابة | المفاتيح في `.env` | الحالة |
|---|---|---|
| MyFatoorah | `MYFATOORAH_API_URL`، `MYFATOORAH_API_KEY` | ✅ |
| ARB | `ARB_TRANPORTAL_ID`، `ARB_TRANPORTAL_PASSWORD`، `ARB_TRANPORTAL_RESOURCE_KEY`، `ARB_TRANPORTAL_HOSTED_URL` | ❌ |
| URPay | `URPAY_MODE`، `URPAY_PAYMENT_URL`، `URPAY_USERNAME`، `URPAY_PASSWORD`، `URPAY_CLIENT_ID`، `URPAY_TERMINAL_ID`، `URPAY_MERCHANT_WALLET_NUMBER`، `URPAY_MERCHANT_ID` | ❌ |

كل البوابات دي محتاجة **عقد تاجر** مع البنك أو الشركة، مش مجرد تسجيل.

## 14. تحديد الدولة بالـ IP

- مجاني ومن غير مفتاح: لو Cloudflare مستخدم بياخد `CF-IPCountry`، وإلا ipwho.is، وإلا ip-api.com.
- **من 2026-10-05:** الدولة بتتحدد **مرة واحدة مع كل تسجيل دخول** وتتحفظ في `users.logged_in_country_id`، ولو مش موجودة أو مقفولة بتبقى السعودية (SA). المحفظة وطرق الدفع والأسعار كلها ماشية عليها (`CountryResolver`).
- **للإنتاج:** الخدمات المجانية ليها حد للطلبات، وip-api.com لغير الاستخدام التجاري. **NEEDS-DECISION:** نستخدم Cloudflare أو MaxMind GeoLite2 (مجاني بحساب، وقاعدة بيانات على السيرفر).

---

## إعدادات السيرفر (مش خدمات، بس من غيرها حاجات بتقف)

| الإعداد | ليه | الأمر / القيمة |
|---|---|---|
| **storage link** | الصور والفويس والحالات بتتعرض من `public/storage`، ومن غيره بيرجع 403 | `php artisan storage:link` (مرة واحدة على كل سيرفر) |
| **config cache** | Apache على ويندوز، وأي استضافة كتير الطلبات، بيضيّع `.env` مع الطلبات المتزامنة | `php artisan config:cache` بعد أي تعديل في `.env` |
| **Cron (الـ scheduler)** | **الرسائل المجدولة والتذكيرات وملخص الهدوء** (`chat:send-scheduled` و`chat:send-reminders` و`chat:quiet-digest` كل دقيقة، و`chat:moment-reminders` (تذكير التواريخ الشخصية) كل 10 دقايق، و`chat:task-reminders` (ميعاد المهام) و`chat:calendar-reminders` (تذكيرات التقويم) كل دقيقة، ومن غيره عمرهم ما هيتبعتوا)، وانتهاء المكالمات اللي محدش رد عليها (كل دقيقة)، ومسح الرسائل اللي بتختفي والحالات المنتهية (كل ساعة)، ومطابقة المحافظ (كل ساعة). محلياً: `php artisan schedule:work` | `* * * * * php /path/artisan schedule:run` — **متاح في cPanel على الاستضافة المشتركة** |
| **Queue worker** | الإيميلات العادية متبعتة على queue (`QUEUE_CONNECTION=database`). أكواد التحقق واسترجاع PIN المحفظة بقت بتتبعت فورًا من غير queue (2026-10-11)، بس لازم إعدادات SMTP في `.env` تكون صح | VPS: `php artisan queue:work` شغال دايماً. استضافة مشتركة: cron كل دقيقة `php artisan queue:work --stop-when-empty` |
| **TRUSTED_PROXIES** | IP المستخدم الحقيقي ورا Cloudflare / nginx / ngrok | `127.0.0.1,::1` محلياً، و`*` ورا Cloudflare أو load balancer |
| **APP_URL** | روابط الملفات والوسائط | الدومين الحقيقي بـ `https://` |
| **composer dump-autoload** | بعد سحب موديول جديد في `Modules/*` | `composer dump-autoload` |

## الأوامر: بعد كل سحب للكود أو نشر على سيرفر

بالترتيب ده. كلهم آمنين لو اتكرروا.

| # | الأمر | إمتى | ليه |
|---|---|---|---|
| 1 | `composer install` ثم `composer dump-autoload` | بعد أي سحب فيه موديول جديد في `Modules/*` أو تغيير في `composer.json` | من غيره Laravel مايعرفش كلاسات الموديول الجديد، و`optimize:clear` نفسه بيقع (حصلت مع موديول SMS) |
| 2 | `php artisan migrate` | بعد أي سحب فيه migrations جديدة | آخرهم: `2026_10_05_100000` (`users.logged_in_country_id`)، و`100100` (`checkouts` + تصنيف الدفتر `service_revenue`)، و`100200` (التصنيفات، والباقات، وبوابات التجار، وتوثيق القنوات)، و`100300` (الاستوريهات العامة واستوريهات دُر)، و`2026_10_06_100000`–`100400` (قوائم البث، والدواير، والـ Threads، والقرارات، والهدوء الذكي)، و`2026_10_07_100000` (المناسبات: الكتالوج، والتواريخ، والتفضيلات، و**بيزرع الـ 70 مناسبة لوحده**)، و`100100` (`users.timezone` وكروت المناسبات المجدولة)، و`100200` (الكروت الجماعية والكبسولات)، و`100300` (تذكير التواريخ الشخصية)، و`2026_10_08_100000` في موديول الـ AI (`ai_messages.safety`) وفي الشات (`chat_tasks`)، و`2026_10_09_100000` (التقويم: `chat_calendar_items` و`chat_calendar_preferences` و`chat_settings.calendar_enabled`)، و`2026_10_10_100000` (ألوان الفولدرات، ومجلدات المفضلة، ونصوص الفويس، وPIN المحادثة، و`users.chat_username`، وغرفة القرار) |
| 3 | `php artisan db:seed --class="Modules\Chat\Database\Seeders\ChatDatabaseSeeder"` | أول مرة على أي سيرفر | أسباب البلاغات والثيمات الافتراضية. مابيعملش حاجة لو الجداول فيها بيانات |
| 4 | `php artisan db:seed --class="Database\Seeders\Admin\AdminPermissionSeeder"` | أول مرة، وبعد أي صلاحية جديدة | صلاحيات الشات (`chat-settings`، `chat-themes`، `chat-report-types`، `chat-stickers`، `chat-reports`، والجداد: `chat-categories`، `chat-packages`، `chat-portals`، `chat-channels`، `chat-dorr-stories`، `chat-moments`). **وبعده لازم تدي الأدوار الصلاحيات الجديدة من لوحة الأدمن**، وإلا الصفحات مش هتظهر غير للسوبر أدمن |
| 5 | `php artisan optimize:clear` ثم `php artisan config:cache` | بعد أي سحب، وبعد أي تعديل في `.env` | يمسح الكاش القديم (إعدادات، routes، views) ويبني الإعدادات من جديد |
| 6 | `php artisan cache:forget chat.settings` | لو إعدادات الشات باينة قديمة | إعدادات الشات متخزنة في الكاش على طول. الـ migrations الجديدة بتمسحها لوحدها، والأمر ده للاحتياط |
| 7 | `npm run build` | بعد أي تعديل في الواجهة (Vue أو ملفات الترجمة) أو مفاتيح `VITE_*` | لوحة الأدمن وشات الويب بيتبنوا من الملفات دي |
| 8 | `php artisan storage:link` | مرة واحدة على كل سيرفر | من غيره الصور والفويس والحالات بترجع 403 |

**وقت التطوير على الجهاز:**

| الأمر | ليه |
|---|---|
| `php artisan schedule:work` | بديل الـ cron محلياً. من غيره الرسايل المجدولة مش هتتبعت، والمكالمات اللي محدش رد عليها مش هتبقى "فائتة" |
| `php artisan queue:work` | الإيميلات اللي على الـ queue (الأكواد بتتبعت فورًا) |
| `php artisan test` | الاختبارات. **مش** `composer test`، لأن Composer بيقطعها بعد 300 ثانية والاختبارات كلها بتاخد أكتر من كده. الاختبارات بتشتغل على SQLite في الذاكرة بس، و`tests/TestCase.php` بيرفض يشغلها على أي داتا بيز تانية |

## الأندرويد

| الإعداد | فين | ملاحظة |
|---|---|---|
| عنوان السيرفر | `androidApp/local.properties`: `dorr.apiHost=...`، `dorr.apiScheme=https` | لكل مطوّر عنوانه، ومش بيترفع على git |
| OneSignal App ID | من السيرفر تلقائياً | مفيش حاجة في التطبيق |
| Pusher | من السيرفر تلقائياً | مفيش حاجة في التطبيق |
| توقيع النسخة النهائية (Keystore) | `androidApp/` + Play Console | **ناقص** — لازم قبل الرفع على Google Play |
| موديل قص الملصقات (ML Kit) | من Google Play Services على الموبايل | بيتنزل لوحده أول ما التطبيق يتسطب (`com.google.mlkit.vision.DEPENDENCIES` في الـ manifest). موبايل من غير Play Services: القص مش هيشتغل، والدايرة والمربع شغالين |
| بناء التطبيق لو الـ Kotlin daemon وقع | سطر الأوامر | `gradlew :app:compileDebugKotlin -Pkotlin.compiler.execution.strategy=in-process` |
| JDK | Android Studio | ماتحطش `org.gradle.java.home` في `gradle.properties` (مسار جهاز واحد بس). Gradle بياخد JDK بتاع Android Studio أو `JAVA_HOME` |

## ترتيب مقترح للتجهيز

1. **Pusher و OneSignal و Firebase:** من غيرهم الشات مش لحظي ومفيش إشعارات (أهم حاجة).
2. **LiveKit Cloud:** المكالمات.
3. **Giphy:** الصور المتحركة.
4. **SMS أو WhatsApp:** قبل الإنتاج، عشان نقفل الكود الثابت `1234`.
5. **بوابات الدفع الناقصة ومفاتيح الـ AI:** حسب الخطة التجارية.
