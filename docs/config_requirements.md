# متطلبات الإعداد — كل اللي محتاجين نجيبه من برا (Config Requirements)

> المرجع الوحيد لكل **حساب أو مفتاح أو خدمة خارجية** محتاجها دُر عشان تشتغل.
> لكل بند: بيعمل إيه، بيتحط فين، حالته على جهاز التطوير الحالي، ولو بيشتغل على **استضافة مشتركة (Shared Hosting)** ولا محتاج **VPS**.
>
> آخر مراجعة: 2026-10-03. أي مفتاح بيتغير في `.env` لازم بعده **`php artisan config:cache`** (السبب في [14-DEPLOYMENT.md](14-DEPLOYMENT.md)).

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
| 10 | **مزودي الـ AI** (OpenAI / Anthropic / Google / Groq) | دُر AI | لوحة الأدمن ← AI | ⚠️ 1 من 4 بس فيه مفتاح | ✅ |
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

## 11 و12 و13. بوابات الدفع (المحفظة)

| البوابة | المفاتيح في `.env` | الحالة |
|---|---|---|
| MyFatoorah | `MYFATOORAH_API_URL`، `MYFATOORAH_API_KEY` | ✅ |
| ARB | `ARB_TRANPORTAL_ID`، `ARB_TRANPORTAL_PASSWORD`، `ARB_TRANPORTAL_RESOURCE_KEY`، `ARB_TRANPORTAL_HOSTED_URL` | ❌ |
| URPay | `URPAY_MODE`، `URPAY_PAYMENT_URL`، `URPAY_USERNAME`، `URPAY_PASSWORD`، `URPAY_CLIENT_ID`، `URPAY_TERMINAL_ID`، `URPAY_MERCHANT_WALLET_NUMBER`، `URPAY_MERCHANT_ID` | ❌ |

كل البوابات دي محتاجة **عقد تاجر** مع البنك أو الشركة، مش مجرد تسجيل.

## 14. تحديد الدولة بالـ IP

- مجاني ومن غير مفتاح: لو Cloudflare مستخدم بياخد `CF-IPCountry`، وإلا ipwho.is، وإلا ip-api.com.
- **للإنتاج:** الخدمات المجانية ليها حد للطلبات، وip-api.com لغير الاستخدام التجاري. **NEEDS-DECISION:** نستخدم Cloudflare أو MaxMind GeoLite2 (مجاني بحساب، وقاعدة بيانات على السيرفر).

---

## إعدادات السيرفر (مش خدمات، بس من غيرها حاجات بتقف)

| الإعداد | ليه | الأمر / القيمة |
|---|---|---|
| **storage link** | الصور والفويس والحالات بتتعرض من `public/storage`، ومن غيره بيرجع 403 | `php artisan storage:link` (مرة واحدة على كل سيرفر) |
| **config cache** | Apache على ويندوز، وأي استضافة كتير الطلبات، بيضيّع `.env` مع الطلبات المتزامنة | `php artisan config:cache` بعد أي تعديل في `.env` |
| **Cron (الـ scheduler)** | انتهاء المكالمات اللي محدش رد عليها (كل دقيقة)، ومسح الرسائل اللي بتختفي والحالات المنتهية (كل ساعة)، ومطابقة المحافظ (كل ساعة) | `* * * * * php /path/artisan schedule:run` — **متاح في cPanel على الاستضافة المشتركة** |
| **Queue worker** | إيميلات التحقق متبعتة على queue (`QUEUE_CONNECTION=database`) | VPS: `php artisan queue:work` شغال دايماً. استضافة مشتركة: cron كل دقيقة `php artisan queue:work --stop-when-empty` |
| **TRUSTED_PROXIES** | IP المستخدم الحقيقي ورا Cloudflare / nginx / ngrok | `127.0.0.1,::1` محلياً، و`*` ورا Cloudflare أو load balancer |
| **APP_URL** | روابط الملفات والوسائط | الدومين الحقيقي بـ `https://` |
| **composer dump-autoload** | بعد سحب موديول جديد في `Modules/*` | `composer dump-autoload` |

## الأندرويد

| الإعداد | فين | ملاحظة |
|---|---|---|
| عنوان السيرفر | `androidApp/local.properties`: `dorr.apiHost=...`، `dorr.apiScheme=https` | لكل مطوّر عنوانه، ومش بيترفع على git |
| OneSignal App ID | من السيرفر تلقائياً | مفيش حاجة في التطبيق |
| Pusher | من السيرفر تلقائياً | مفيش حاجة في التطبيق |
| توقيع النسخة النهائية (Keystore) | `androidApp/` + Play Console | **ناقص** — لازم قبل الرفع على Google Play |

## ترتيب مقترح للتجهيز

1. **Pusher و OneSignal و Firebase:** من غيرهم الشات مش لحظي ومفيش إشعارات (أهم حاجة).
2. **LiveKit Cloud:** المكالمات.
3. **Giphy:** الصور المتحركة.
4. **SMS أو WhatsApp:** قبل الإنتاج، عشان نقفل الكود الثابت `1234`.
5. **بوابات الدفع الناقصة ومفاتيح الـ AI:** حسب الخطة التجارية.
