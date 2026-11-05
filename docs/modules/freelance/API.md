# Freelance API — العقد الموحد

> كل مهمة جديدة في [freelance_tasks.md](../../freelance_tasks.md) بتضيف قسمها هنا. الملف ده هو المرجع الوحيد لتطبيق المحترف والموقع والداشبورد وتطبيق دُرر.

## 1. البادئات والحراس

| من | البادئة | الحارس | الاستخدام |
|---|---|---|---|
| المحترف (تطبيق + موقع + داشبورد) | `/api/freelance/v1/*` | `freelance_api` | API واحد للتلاتة |
| العميل (مستخدم دُرر) | `/api/mobile/v1/freelance/*` | `user_api` | من تطبيق دُرر |
| الأدمن | `/api/admin/v1/*` | `admin_api` | لوحة التحكم |

- token المحترف ما بيفتحش بادئة العميل، والعكس. المحترف حساب مستقل عن حساب دُرر حتى لو نفس الرقم.
- كل البادئات بتستخدم نفس الـ Services. الـ controller بيستقبل ويرد، والمنطق في `Modules/Freelance/app/Services`.

## 2. شكل الرد

كل الردود بـ `App\Support\Api\ApiResponse`:

```json
{ "success": true, "status": "success", "code": 200, "message": "…", "data": {}, "pagination": null }
```

- **القوائم:** `data` مصفوفة، و`pagination` فيها بيانات الصفحات (`current_page` و`per_page` و`total` و`last_page` و`has_more_pages`).
- **صفحات القوائم الطويلة:** `?page=&per_page=` (الافتراضي **15**، الحد الأقصى 50). التطبيقين بيجيبوا أول 15 ويكمّلوا الباقي لوحدهم مع السكرول من غير تايمر (`has_more_pages`). اللي بيتصفّح بيها: مشاريعي، عروض مشروع، المحترفين المفضّلين، الإيصالات، عروضي، دعوات المحترف، وسجل المحفظة وطلبات السحب؛ الرسايل بتتصفّح بـ `before`/`limit` (40).
- **الخطأ:** `success: false` ومعاه `code` (HTTP)، و`message` مترجمة، وللأخطاء اللي بتخص الخدمة `error_code` يبدأ بـ `freelance_` وأحياناً `data` فيها تفاصيل.
- **التحقق:** 422 و`errors` بأسماء الحقول.
- **الأسماء:** `snake_case`، والتواريخ ISO-8601 (`2026-10-19T12:00:00+00:00`)، والمبالغ بالعملة الصغرى (يتحدد في الدفعة 5).
- **اللغة:** الـ header `Accept-Language: ar|en`. الرسائل والأخطاء بتترجم حسبه.

## 3. الإصدارات

- `v1` ثابتة: الإضافة (حقل جديد، endpoint جديد) مسموحة جواها. حذف حقل أو تغيير معناه أو إلغاء endpoint **بيروح `v2`**.
- `GET …/config` بيرجّع `api_version` و`min_app_version` (الإعداد `freelance.min_app_version` / `FREELANCE_MIN_APP_VERSION`). لو نسخة التطبيق أقدم منها، التطبيق يطلب من المستخدم يحدّث.

## 4. الإعدادات (مرة واحدة عند فتح التطبيق، بدون polling)

`GET /api/freelance/v1/config` (المحترف) و`GET /api/mobile/v1/freelance/config` (العميل):

```json
{
  "api_version": "v1", "min_app_version": "1.0.0", "role": "freelancer|client",
  "country_id": 190, "service": true,
  "features": { "projects": false, "bidding": true, "…": false },
  "terms_version": "1",
  "limits": { "upload_max_kb": 20480, "avatar_max_kb": 5120 }
}
```

- `service` و`features` من مفاتيح الخصائص (FL-002) للدولة. مفتاح مقفول = `false`، ولو `service` مقفولة كله `false`.
- **دولة المحترف:** دولة حسابه. **دولة العميل:** `logged_in_country_id` (المكان اللي دخل منه) وإلا `country_id`.
- التطبيق يطلبه عند الفتح وعند رجوع الاتصال، ويبني الشاشات على `features`.

## 5. حماية الـ routes بالمفاتيح

أي route جديدة بتتحط عليها `freelance.feature:<المفتاح>`:

```php
Route::middleware(['auth:freelance_api', 'freelance.feature:bidding'])->post('offers', …);
```

لو المفتاح (أو الخدمة) مقفول في دولة صاحب الطلب: **403** و`error_code = freelance_feature_off` و`data.feature` باسم المفتاح.

## 6. أكواد الأخطاء الحالية

| الكود | HTTP | المعنى |
|---|---|---|
| `freelance_not_available` | 403 | الخدمة مقفولة في الدولة (وقت الدخول) |
| `freelance_feature_off` | 403 | الخاصية مقفولة في الدولة |
| `freelance_terms_outdated` | 422 | نسخة الشروط قديمة (`data.current_version`) |
| `freelance_specialty_two_levels` | 422 | التخصص الفرعي لازم يبقى تحت تخصص رئيسي (مستويين بس) |
| `freelance_specialty_has_children` | 422 | التخصص عنده تخصصات فرعية فمينفعش يتحذف |
| `freelance_specialty_not_found` | 404 | التخصص مش موجود |

## 7. الدخول والحساب (FL-003) — `/api/freelance/v1`

| الطريق | الوظيفة |
|---|---|
| `POST auth/otp` · `auth/verify` · `auth/resend` | الدخول بالموبايل والكود (`dial_code`, `phone`, `code`, و`device_id` / `device_name` / `platform` عند `verify`). جلسة لكل جهاز |
| `GET auth/me` · `POST auth/logout` | بياناتي (فيها `missing` و`terms`) والخروج |
| `GET appearance` · `PUT appearance` | شكل التطبيق للفريلانسر (نفس شكل وفاليديشن تطبيق الـ user): `dark_mode` (system/light/dark) و`uses_default_colors` و`custom_light_tokens` / `custom_dark_tokens` و`mobile_app_font_id`. الرد فيه `font` و`available_fonts` و`resolved` و`default`. جدول `freelancer_mobile_appearances` (صف لكل فريلانسر)، والـ payload من `MobileAppearanceService::payload` |
| `GET ratings/mine` · `POST ratings` | تقييم التطبيق (نفس تقييم تطبيق الـ user: `stars` من 1 لـ 5 بخطوة ربع نجمة، و`comment` اختياري 5–300 حرف). تقييم واحد لكل فريلانسر، والـ POST التاني بيعدّل. بيظهر في صفحة التقييمات في الداشبورد (`author` = Freelancer). `MobileRatingService` بقى بياخد أي موديل صاحب تقييم |
| `GET faqs` | الأسئلة الشائعة العامة (نفس `mobile/v1/faqs`) |
| `GET my-projects?group=all\|active\|completed\|closed\|disputes` | المشاريع اللي اتختارت ليها (تاب «شغلي»): `active` = شغّال أو مستني موافقتي، `completed` = خلص، `closed` = اترفض/اتلغى/انتهت مدته. الأحدث الأول وصفحات (`per_page`). كل صف: `uuid` الاتفاقية و`status` و`project{uuid,title}` والسعر والعملة والأيام و`milestones_count`/`milestones_done` و`has_dispute` (فيه نزاع لسه ما اتحسمش)، و`disputes` = اللي فيها نزاع مفتوح، وبدون group = الكل. المسودة اللي العميل لسه ما بعتهاش مش بتظهر |
| `GET referrals/my-code` | كود الدعوة بتاع الفريلانسر (حساب > شارك التطبيق مع أصدقائك): `{code, is_active, applied}`. نفس جدول `referral_codes` وبادئة `DORRFC-` بتاعة تطبيق الـ user، المالك `referrable_type = freelancer` (متسجل في `config/referral.php`). الكود ثابت، بيتعمل أول طلب |
| `GET security/devices` · `DELETE security/devices/{session}` · `POST security/devices/logout-others` · `GET security/events` | الأمان (إعدادات > الأمان): الأجهزة المسجّل دخولها (الحالي أولاً)، تسجيل خروج جهاز أو كل الأجهزة الأخرى، وسجل الأمان (`type` و`warnings=1` وصفحات). هي نفس جلسات وسجل تطبيق الـ user (`account_sessions` و`security_events`)، فبتظهر للدعم في الداشبورد؛ تسجيل الدخول بقى بيعدي على `AccountSessionService` وتنبيه «جهاز جديد» شغال. تسجيل خروج جهاز بيشيله كمان من `devices` |
| `GET security/devices` · `DELETE security/devices/{session}` · `POST security/devices/logout-others` · `GET security/events` | الأمان (إعدادات > الأمان): الأجهزة المسجّل دخولها (الحالي أولاً)، تسجيل خروج جهاز أو كل الأجهزة الأخرى، وسجل الأمان (`type` و`warnings=1` وصفحات). هي نفس جلسات وسجل تطبيق الـ user (`account_sessions` و`security_events`)، فبتظهر للدعم في الداشبورد؛ تسجيل الدخول بقى بيعدي على `AccountSessionService` وتنبيه «جهاز جديد» شغال. تسجيل خروج جهاز بيشيله كمان من `devices` |
| `GET legal-pages?type=privacy\|term` | **عامة** (من غير تسجيل دخول، شاشة الدخول بتعرضها): سياسة الخصوصية أو الشروط الخاصة بخدمة الفريلانس (`legal_pages.service_id` = خدمة `freelance`)، ولو الخدمة ملهاش صفحة منشورة بترجع الصفحة العامة. بيرجع `data.content` باللغة الحالية. السيدر `FreelanceLegalPageSeeder` |
| `GET support-help` · `POST support-help/{node}/feedback` | قائمة المساعدة الموجّهة (الشات السريع) — المواضيع اللي `audience = freelancer` بس، ورد واحد فيه الشجرة كلها. الـ feedback (`solved`) بيتعدّ في الداشبورد، و«محتاج موظف» بيوصّل إشعار لفريق الدعم |
| `GET support-tickets` · `POST support-tickets` · `GET support-tickets/{id}` · `PATCH support-tickets/{id}/status` | تذاكر الدعم (`title` و`body` وصورة اختيارية). نفس جدول `support_tickets` وصفحة الدعم في الداشبورد، والتذكرة بتتربط بـ `freelancer_id` (جنب `user_id` و`driver_id`). الحالة من الفريلانسر: `closed` أو `reopened` بس. تذكرة حد تاني = 404 |
| `GET support-tickets/{id}/messages` · `POST support-tickets/{id}/messages` · `POST support-tickets/{id}/auto-reply-feedback` | المحادثة جوه التذكرة (`order=desc` الأحدث الأول، وصورة اختيارية) والرد على الإجابة الآلية. اللحظي على قناة الفريلانسر (`support.message` / `support.ticket.updated`) |
| `GET config` | الإعدادات (القسم 4) |
| `PUT profile` · `POST profile/avatar` | إكمال البيانات: `name` (إجباري) و`headline` و`bio` و`years_of_experience` و`contact_languages[]` و`city` و`gender` و`date_of_birth` و`country_id` و`locale`، والصورة (5MB). الحقل اللي ما اتبعتش بيفضل، والمبعوت فاضي بيتمسح. الإيميل مش من هنا |
| `POST profile/email/send-code` · `profile/email/confirm` | `{email}` ← كود على الإيميل (ما بيتغيّرش حاجة لحد التأكيد)، ثم `{code}` ← الإيميل يبقى متحقق منه. الإيميل المأخوذ بيترفض |
| `POST terms/accept` | `{version}` |
| `GET devices` · `DELETE devices/{id}` | أجهزتي وإنهاء جلسة |
| `DELETE account` | `{confirm: true}` |

## 7-ب. المحفظة (FL-005) — `/api/freelance/v1/wallet`

نفس شاشات وقواعد محفظة المستخدم بالظبط (الرد والـ headers: `X-Wallet-Pin` للعمليات المالية، `Idempotency-Key` للشحن، و`X-Country` اختياري، وإلا دولة حساب المحترف):

| الطريق | الوظيفة |
|---|---|
| `GET wallet` · `wallet/transactions` · `wallet/transactions/{uuid}` · `wallet/eligibility` | الرصيد والكشف وتفاصيل الحركة |
| `GET/POST/PUT wallet/pin` · `wallet/pin/verify` · `wallet/pin/recovery*` · `wallet/pin/recover` · `wallet/pin/unfreeze` | الـ PIN وتعافيه |
| `POST wallet/device/verify-code` · `wallet/device/confirm` | ثقة الجهاز |
| `GET wallet/payment-methods` · `POST wallet/topups/quote` · `POST wallet/topups` (PIN) · `POST wallet/topups/{uuid}/confirm` (PIN) · `GET wallet/topups/{uuid}` | **الشحن** (لسداد الباقات لاحقاً) |
| `POST wallet/checkouts` · `GET wallet/checkouts/{uuid}` · `POST wallet/checkouts/{uuid}/pay` (PIN) · الكوبونات | شاشة الدفع الموحدة |

**السحب مفتوح للمحترف (قرار المالك 2026-10-20):** نفس مسارات السائق بالظبط على حارس `freelance_api`:

| الطريق | الوظيفة |
|---|---|
| `GET wallet/withdrawal-methods` · `PATCH …/{id}/favorite` · `DELETE …/{id}` | وجهات السحب (بنكي بـ IBAN أو محفظة موبايل)، بتظهر مقنّعة |
| `POST wallet/withdrawal-methods` · `PUT …/{id}` (PIN) | إضافة وتعديل وجهة |
| `GET wallet/withdrawals` · `…/{id}` · `…/{id}/receipt` | طلباتي وإيصال التحويل بعد الموافقة (خاص، صاحبه بس) |
| `POST wallet/withdrawals` (PIN + `Idempotency-Key`) `{withdrawal_method_id, amount_minor}` | طلب سحب: **بيحجز** المبلغ من القابل للسحب، والأدمن بيحوّل ويعتمد (`admin/v1/withdrawal-requests/{id}/approve` بإيصال) أو يرفض بسبب، والمحترف بيتبلّغ. الرصيد «للخدمات فقط» (البونص) مش بيتسحب أبداً. |

**لسه مش مفتوح للمحترف:** التحويل لمستخدم تاني (`wallet/transfers*`، `wallet/beneficiaries`) — FL-508 → FL-L15 (ربط بوابات الدفع للخدمة).

## 7-ج. الشات (FL-006)

شات خاص بالعمل الحر، مستقل عن موديول Chat. المحترف على `/api/freelance/v1/chat/*` والعميل على `/api/mobile/v1/freelance/chat/*` بنفس الشكل. الغرفة بيفتحها السيرفر بس (مفيش endpoint لفتح غرفة)، ومحدش غير الاتنين اللي فيها يشوفها (غير كده 404).

| الطريق | الوظيفة |
|---|---|
| `GET chat/conversations` | غرفي (صفحات، `per_page`) فيها `unread` و`last_message_preview` و`peer` و`can_write` |
| `GET chat/conversations/{uuid}` | تفاصيل غرفة (`context`، `status`) |
| `GET chat/conversations/{uuid}/messages?before=&limit=` | الرسائل الأحدث أولاً. `pagination.next_before` و`has_more` للصفحة اللي بعدها |
| `POST chat/conversations/{uuid}/messages` | `body` و/أو `file` (صور pdf وملفات office و zip، لحد `limits.upload_max_kb`) و`client_id` (لو اتبعتت مرتين بتتحسب مرة) |
| `POST chat/conversations/{uuid}/read` | علّم رسائل الطرف التاني مقروءة |
| `DELETE chat/messages/{id}` | سحب رسالتي خلال 5 دقايق (بتفضل «محذوفة» في الغرفة) |

- **لحظي:** قناة خاصة `freelance-chat.{uuid}` (تصريح الاتنين فقط عبر `broadcasting/auth` بحارس `freelance_api` أو `user_api`): حدث `freelance.message` (نفس شكل الرسالة، `mine = null`) وحدث `freelance.read` (`reader`, `up_to_id`). مفيش polling: التطبيق يطلب القائمة عند الفتح وعند رجوع الاتصال.
- **الكتابة:** الغرفة `open` ومفتاح `chat` شغال في دولة المحترف، وإلا `409 freelance_chat_closed` (القراءة تفضل شغالة).
- **الأخطاء:** `freelance_chat_closed` (409)، `freelance_chat_empty` (422)، `freelance_message_not_deletable` (422).

## 7-د. الإشعارات والـ realtime (FL-007) — `/api/freelance/v1`

نفس endpoints المستخدم والسائق بالظبط (الحارس هو اللي بيحدد صاحب الإشعارات):

| الطريق | الوظيفة |
|---|---|
| `GET notifications` · `notifications/unread-count` | القائمة (صفحات) وعدد غير المقروء |
| `POST notifications/read-all` · `notifications/{id}/read` | تعليم مقروء |
| `POST notifications/devices` | `{player_id, platform}` تسجيل جهاز OneSignal (عند كل فتح، الـ id ممكن يتنقل بين حسابات) |
| `DELETE notifications/devices` | `{player_id}` |
| `POST auth/logout` | بياخد `player_id` اختيارياً وبيفصل الجهاز |
| `GET realtime-config` | `enabled`, `key`, `cluster`, `host`, `port`, `use_tls`, `auth_path`, **`channel`** (`private-Modules.Freelance.Models.Freelancer.{id}`)، `onesignal_app_id` |

- **لحظي بدون polling:** التطبيق يطلب `realtime-config` مرة عند الفتح، يشترك في القناة (التصريح بـ `broadcasting/auth` بحارس `freelance_api`)، ويطلب `notifications` مرة عند الفتح ومرة عند رجوع الاتصال.
- **الـ push:** OneSignal بكل اللغات (العربي والإنجليزي) والتطبيق بيعرض بلغة المحترف. تطبيق المحترف ممكن يكون OneSignal app مستقل: `ONESIGNAL_FREELANCE_APP_ID` و`ONESIGNAL_FREELANCE_REST_API_KEY` (فاضيين = بيستخدم العام).
- **شكل الإشعار:** `data.event` = `freelance.<event>` وفي `data` الـ ids اللي التطبيق يفتح بيها الشاشة (مثلاً `project_id`).

### كتالوج الأحداث (`FreelanceNotifier`)

| للمحترف | للعميل |
|---|---|
| `invitation.received` · `offer.accepted` · `offer.rejected` · `milestone.approved` · `milestone.revision` · `payment.held` · `payment.released` · `verification.approved` · `verification.rejected` · `dispute.opened` · `dispute.resolved` · `quota.low` · `subscription.started` · `subscription.renewing` · `subscription.renewed` · `subscription.renewal_failed` · `subscription.expired` | `offer.received` · `milestone.delivered` · `dispute.opened` · `dispute.resolved` · `review.received` |

الاستخدام من أي خدمة: `app(FreelanceNotifier::class)->notify($receiver, 'offer.accepted', ['project' => $title], ['project_id' => $id])`. المتغيرات المطلوبة لكل حدث في `FreelanceNotifier::EVENTS`، والنصوص في `lang/{ar,en}/notifications.php` (`freelance_{audience}_{event}_title|body`).

## 7-هـ. الأسعار والقواعد (FL-008)

- **في `GET …/config` للتطبيقين:** `currency` (`code`, `symbol`, `decimal_places`) و`policy` (كل المفاتيح التالية لدولة صاحب الطلب، على مستوى الدولة).
- **الوحدات:** النسب بالـ basis points (1000 = 10%)، والمبالغ (`*_minor` والرسم الثابت) بالوحدة الصغرى لعملة الدولة، و`null` = من غير حد.

| المفتاح | المعنى |
|---|---|
| `commission.type` / `client_fee.type` | `percent` أو `fixed` |
| `commission.value` / `client_fee.value` | basis points لو نسبة، أو مبلغ لو ثابت |
| `commission.min_minor` · `commission.max_minor` · `client_fee.min_minor` · `client_fee.max_minor` | حدود الرسم بعد حسابه |
| `offers.free_monthly_quota` · `offers.window_days` · `offers.max_per_project` | العروض |
| `review.days` · `review.auto_approve` · `review.free_revisions` | المراجعة والتسليم |
| `milestones.max_count` · `milestones.min_amount_minor` | المراحل |
| `project.min_budget_minor` · `project.max_budget_minor` | ميزانية المشروع |
| `earnings.hold_days` | أيام قبل ما الأرباح تبقى متاحة |

**الحساب:** العميل يدفع = المبلغ + رسم العميل، والمحترف ياخد = المبلغ − العمولة. النسبة بتتقرّب لأقرب وحدة صغرى (نص لفوق)، وبعدين بيتطبّق الحد الأدنى والأقصى، والعمولة ما تعديش المبلغ نفسه.

**الأدمن:** `GET admin/v1/freelance-policies/options` · `scopes` · `GET admin/v1/freelance-policies?country_id=&specialty_id=` (`inherited`, `own`, `effective`, `currency`) · `PUT` (`{country_id, specialty_id?, data}`، و`data` فاضية = رجوع للموروث) · `POST preview` (`{country_id, specialty_id?, amount_minor, data?}`) · `POST copy` (`{from_country_id, to_country_id}` ← `{copied, skipped}`) · `GET guide?country_id=` (ملف Word عربي يشرح الصفحة). الخطأ: `freelance_policy_invalid` (422، `data.keys`).

## 7-و. الكتالوجات للتطبيقات (FL-101)

`GET freelance/v1/specialties` (المحترف) و`GET mobile/v1/freelance/specialties` (العميل): شجرة التخصصات النشطة (الرئيسي وتحته فروعه النشطة بالترتيب) بلغة الطلب `Accept-Language`، فيها `id` و`name` و`description` و`sort_order` و`children`. `pagination.version` بيتغير لما الكتالوج يتغير عشان التطبيق يكاشي لحد ساعتها. بتتطلب مفتاح `freelance` شغال في دولة صاحب الطلب (`freelance_feature_off` غير كده). إيقاف تخصص رئيسي بيخفي فروعه.

## 7-ز. المهارات (FL-102)

- `GET freelance/v1/skills` (المحترف) و`GET mobile/v1/freelance/skills` (العميل): المهارات النشطة بلغة الطلب، `?specialty_id=` (التخصص الرئيسي بيشمل فروعه) و`&q=` بحث بالاسم (بيتجاهل الحروف الكبيرة والتشكيل) و`per_page`، صفحات في `pagination`. المحترف بيشوف كمان اقتراحاته المعلّقة (`pending: true`).
- `POST freelance/v1/skills/suggest` (المحترف بس) `{name, locale?: ar|en, specialty_id?}`: لو المهارة موجودة (بأي صيغة أو لغة أو كاسم بديل) بترجع هي (`created: false`)، ولو جديدة بتتعمل `pending` (`201`، `created: true`). الأخطاء: `freelance_skill_unavailable` (422: موقوفة أو مرفوضة)، `freelance_skill_suggest_limit` (429: سقف اليوم).
- **الأدمن:** `admin/v1/freelance-skills` (قائمة بفلتر `status` و`specialty_id` و`search`، و`pagination.counts` بعدد كل حالة) · `POST` · `PUT {id}` · `PATCH {id}/status` · `POST {id}/approve {translations?, specialty_ids?}` · `POST {id}/reject {reason?}` · `POST {id}/merge {target_id}` · `DELETE {id}` · `POST delete-multiple` · `GET options`. الأخطاء: `freelance_skill_duplicate` (422، `data.skill_id`)، `freelance_skill_translations_missing` (422، `data.locales`)، `freelance_skill_invalid_state` (422)، `freelance_skill_merge_invalid` (422)، `freelance_skill_in_use` (409).
- **إشعارات المحترف:** `freelancer.skill.approved` و`freelancer.skill.rejected` في كتالوج `FreelanceNotifier`.

## 7-ح. اكتمال الملف (FL-103)

كل رد عن حساب المحترف (`auth/me`، `profile`، `terms/accept`، `profile/email/confirm`...) فيه `completeness`: `percent` (0 لـ 100) و`missing` (الخطوات الناقصة) و`steps` (كل خطوة true/false). الأوزان: الاسم 15، الصورة 15، العنوان المهني 15، النبذة 20 (50 حرف على الأقل)، الإيميل المتحقق منه 15، لغات التواصل 10، سنوات الخبرة 10. **بتقيس بس**: حالة الحساب (`incomplete` ← `pending_verification`...) بتمشي على الاسم والشروط، وبعدها FL-108 وFL-110. FL-104 وFL-105 بيضيفوا خطواتهم (التخصصات والمهارات والمعرض) هنا.

## 7-ط. التخصصات والمهارات والخبرات والشهادات (FL-104)

كلها تحت `/api/freelance/v1/profile/` للمحترف المسجّل بس، وأي صف مش بتاعه بيرجع 404. الحدود في `freelance.profile` (تخصصات 5، مهارات 30، خبرات 20، شهادات 20) والتخطي بيرجع `freelance_profile_limit` (422، `data.max`).

| الطريق | الوظيفة |
|---|---|
| `GET specialties` · `PUT specialties {specialty_ids[], primary_id}` | القائمة كلها بتتبدّل. الأساسي لازم يكون من المختارين، والتخصص الجديد لازم يكون نشط (`freelance_profile_specialty_invalid`، `freelance_profile_primary_invalid`). اللي اتوقف بعد كده بيفضل (`is_active: false`) |
| `GET skills` · `PUT skills {skills:[{skill_id, level?}]}` | `level`: `beginner` · `intermediate` · `advanced` · `expert`. المهارة لازم تكون نشطة أو اقتراحك المعلّق (`pending: true`)، والمدموجة بتتحوّل للباقية، والمكررة بتاخد أعلى مستوى (`freelance_profile_skill_invalid`) |
| `GET experiences` · `POST` · `PUT {id}` · `DELETE {id}` | `title`، `company`، `started_on`، `ended_on`، `is_current` (الحالية من غير نهاية)، `description`. الحالية الأول |
| `GET certificates` · `POST certificates` · `POST certificates/{id}` · `DELETE {id}` | `title`، `issuer`، `issued_on`، `expires_on`، `credential_url` (http أو https بس)، `proof` (ملف jpg/png/pdf حتى 5MB)، و`remove_proof=1` لحذف الملف. التعديل بـ POST عشان الـ multipart |
| `GET certificates/{id}/proof` | تحميل ملف الإثبات: **مفيش رابط عام**، وصاحبه بس (والرد فيه `proof.path` مش `url`) |

الشهادة بيانات بيقدّمها المحترف ومش متحقق منها من المنصة.

## 7-ي. التوثيق والبوابة (FL-107 / FL-108 / FL-110)

**حالات الحساب:** `registered` (دخل بالجوال) ← `profile_ready` (الاسم + الشروط) ← `verification_submitted` (قيد المراجعة) ← `verified` / `verification_rejected` / `changes_requested`، ومعاهم `suspended` و`blocked`.

**في كل رد عن حساب المحترف:** `can_apply` (true للموثّق بس)، و`step` (`complete_profile` · `submit_verification` · `under_review` · `fix_documents` · `suspended` · `blocked` أو `null`)، و`blocked_reasons`.

**البوابة:** route عليها `freelance.verified` بترجع لغير الموثّق **403 `freelance_verification_required`** و`data.step`. التصفح والملف والمستندات والإشعارات مفتوحين قبل التوثيق.

| الطريق (`/api/freelance/v1/`) | الوظيفة |
|---|---|
| `GET verification` | `required[]` (النوع والمستند المرفوع وهل يكفي)، `others[]`، `needs_residency`، `can_submit`، `submit_blockers` (`not_allowed` · `residency` · `missing:{code}` · `expired:{code}`)، `last_request` |
| `POST verification/documents` (multipart) | `document_type_id`، `number`، `expires_on` (بعد النهارده)، `front`، `back` (jpg/png/pdf حتى 8MB). رفع نفس النوع تاني بيستبدل المسودة. مقفول وقت المراجعة (`freelance_verification_document_locked` 409) |
| `DELETE verification/documents/{id}` | المسودة بس |
| `POST verification/submit` | `freelance_verification_incomplete` / `freelance_verification_residency_required` (422) أو `freelance_verification_not_allowed` (409) |
| `GET verification/documents/{id}/{front\|back}` | ملفك بس، `private, no-store` |

`residency` (`citizen` / `resident`) بيتحط من `PUT profile` لما الدولة بتطلب مستندات مختلفة للمواطن والمقيم.

**الأدمن:** `admin/v1/freelance-document-types` (CRUD + `status` + `options`، `requirements[{country_id, residency}]`) بصلاحية `freelance-document-types`، و`admin/v1/freelance-verifications` (قائمة + `options` + `{uuid}` + `documents/{id}/{side}` + `GET guide` (ملف Word عربي يشرح الصفحة) · `POST {uuid}/decide {decision: approve|reject|changes, reason?, documents?: {id: {status, reason?}}}`) بصلاحية `freelance-verifications`. أخطاء: `freelance_verification_already_decided` (409)، `freelance_verification_reason_required` (422)، `freelance_document_type_in_use` (409).

## 7-ك. معرض الأعمال (FL-105)

**المحترف** (`/api/freelance/v1/profile/portfolio`):

| الطريق | الوظيفة |
|---|---|
| `GET` · `POST` · `PUT {id}` · `DELETE {id}` | `title`، `description` (نص بدون HTML)، `completed_on`، `specialty_id`، `skill_ids[]` (حتى 10، نشطة)، `visibility`، `rights_confirmed`، `external_url` (http/https)، `video_url` (يوتيوب أو فيميو). حتى 30 عمل |
| `PUT order {ids[]}` | الترتيب الجديد (كل `id` بتاعي ومن غير تكرار) |
| `POST {id}/images` (multipart `image`) | jpg/png/webp حتى 5MB، من 200 لـ 8000px، حتى 10 للعمل |
| `GET {id}/images/{media}` · `DELETE {id}/images/{media}` | صورتي (`private, no-store`) / حذفها |

- العمل `public` أو `clients_only` من غير `rights_confirmed` بيترفض (`freelance_portfolio_rights_required`)، ورجوع الإقرار محتاج الرجوع لـ `private`. الفيديو الغلط: `freelance_portfolio_video_invalid`.
- **ردّ العمل:** للمحترف `id` و`visibility` و`rights_confirmed` و`hidden` و`hidden_reason`، ولغيره `uuid` والمحتوى بس. الصورة للمحترف `path` (بتوكنه)، ولغيره `url` موقّع بينتهي.

**القراءة:** `GET /api/mobile/v1/freelance/freelancers/{uuid}/portfolio` (العميل: العلني + للعملاء بس) و`GET /api/freelance/v1/public/freelancers/{uuid}/portfolio` (أي حد: العلني بس). المحترف **الموثّق** بس هو اللي أعماله بتظهر، والمخفي ما بيظهرش. رابط الصورة `GET /api/mobile/v1/freelance/portfolio-images/{media}?signature=…` بينتهي، وبيتراجع إن العمل لسه ظاهر.

**الأدمن:** `POST admin/v1/freelancers/{id}/portfolio/{item}/hide {reason}` و`…/unhide` (صلاحية `freelancers.update`).

## 8. الأدمن

| الطريق | الصلاحية |
|---|---|
| `GET/POST admin/v1/freelance-feature-rules` · `options` · `copy` · `guide` | `freelance-feature-rules` |
| `GET admin/v1/freelancers` · `{id}` · `POST {id}/status` | `freelancers` |
| `admin/v1/freelance-policies` (options · scopes · show · update · preview · copy) | `freelance-policies` |
| `admin/v1/freelance-specialties` (index · show · store · update · destroy · `{id}/status` · `reorder` · `delete-multiple`) | `freelance-catalogs` (view / create / update / delete / multiple-delete) |

## 9. العميل — `/api/mobile/v1/freelance`

| الطريق | الوظيفة |
|---|---|
| `GET config` | الإعدادات (القسم 4) |

*(بيتضاف المشاريع والعروض والاتفاق في الدفعات 2 وما بعدها.)*

## التوفر والأسعار والإجازات (FL-106)

كلها على `freelance_api` تحت `/api/freelance/v1/profile`:

| الطريقة | المسار | ملاحظات |
|---|---|---|
| GET | `availability` | `accepting_requests`, `paused_until`, `pause_reason`, `can_receive_offers`, `on_time_off`, `rate{type,min_minor,max_minor}`, `weekly_hours[]`, `time_off[]` |
| PUT | `availability` | اللي يتبعت بس هو اللي يتغيّر. `accepting_requests=true` بيمسح الإيقاف وملاحظته. `rate_type`: `hourly`/`fixed`/`none` (`none` بيمسح السعر)، والحد الأدنى مطلوب لو مش `none` والأقصى مش أقل منه. `weekly_hours`: لحد 7، `day` 0–6 (0 = الأحد) من غير تكرار، `from`/`to` بصيغة `HH:MM` والنهاية بعد البداية. `paused_until` مش قبل النهاردة |
| GET | `time-off` | الفترات اللي لسه ما خلصتش |
| POST | `time-off` | `starts_on` (مش قبل النهاردة)، `ends_on`، `reason`. حد أقصى 12، ومنع التداخل (`freelance_time_off_overlap`)، `freelance_profile_limit` عند الحد |
| DELETE | `time-off/{id}` | بتاعه بس |

**المنع:** `FreelancerGate::assertCanOffer($freelancer)` = حساب موثّق + `FreelancerAvailabilityService::canReceiveOffers()` (مش موقوف ومش في إجازة) وإلا `freelance_unavailable` (422، `data.reason` = `paused`/`time_off`). الإيقاف بتاريخ بينتهي لوحده بعد اليوم دا (بيتحسب وقت السؤال). `auth/me` فيه `availability`. العروض (FL-301) هتنادي `assertCanOffer`.

## المشاريع من جهة العميل (FL-201 / FL-202)

على `user_api` تحت `/api/mobile/v1/freelance/projects` (ورا مفتاح `projects` لدولة العميل). المشروع بيتعنون بالـ `uuid`، وكل حاجة على مشاريع العميل نفسه بس.

| الطريقة | المسار | ملاحظات |
|---|---|---|
| GET | `/` | مشاريعي، الأحدث الأول، `status` اختياري، `page` و`per_page` (15) |
| POST | `/` | مسودة جديدة. الشكل بس بيتشيك (المسودة ممكن تبقى ناقصة). لحد 20 مشروع مفتوح (`freelance_project_limit`) |
| GET | `budget-rules?specialty_id=` | `min_minor` / `max_minor` / `currency` لدولة العميل (وللتخصص لو اتبعت، وقاعدته بتغلب) |
| GET | `cost-quote?amount_minor=&specialty_id=` | اللي العميل بيدفعه في الآخر لسعر معيّن (السعر + رسم العميل = الإجمالي + العملة) — لفحص «الحد الأقصى للتكلفة» في النموذج قبل وجود المسودة |
| GET | `{uuid}` | المشروع مع `missing` و`can{edit,submit,withdraw,cancel,delete}` و`timeline` |
| PUT | `{uuid}` | تعديل (مسودة أو مطلوب تعديل بس، وإلا `freelance_project_invalid_state` 409). اللي يتبعت بس هو اللي يتغيّر |
| DELETE | `{uuid}` | حذف المسودة بس |
| POST | `{uuid}/submit` | لازم يكون كامل (عنوان ≥ 10، وصف ≥ 30، تخصص، مهارة نشطة واحدة على الأقل، ميزانية، مدة) وجوه حدود الميزانية: `freelance_project_incomplete` (`data.fields`)، `freelance_project_budget_below_min` / `_above_max` (`data.min_minor` / `max_minor`)، `freelance_project_budget_range` |
| POST | `{uuid}/withdraw` | من «قيد المراجعة» لمسودة |
| POST | `{uuid}/cancel` | `reason` اختياري. من قيد المراجعة أو مطلوب تعديل أو منشور |
| POST | `{uuid}/attachments` | multipart `file`: لحد 10، كل واحد لحد `freelance.upload_max_kb`، PDF/صور/Office/نص/ZIP |
| GET / DELETE | `{uuid}/attachments/{media}` | تنزيل (لصاحبه بس) / حذف |

**الحقول:** `title` (120)، `description` (5000)، `specialty_id`، `skill_ids[]` (لحد 10، نشطة)، `budget_type` (`fixed`/`range`)، `budget_min_minor` (المبلغ لو ثابت)، `budget_max_minor`، `duration_days` (1–365)، `visibility` (`public`/`private`).
**الحالات:** `draft` ← `pending_review` ← `published` ← `closed`؛ `pending_review` ← `changes_requested` / `rejected`؛ والإلغاء `cancelled`. كل انتقال من `ProjectStateMachine` ومتسجّل في `freelance_project_events`.

## مراجعة المشاريع (FL-203)

على `admin_api` تحت `/api/admin/v1/freelance-projects` (الصلاحية `freelance-projects`: `view` و`update`). المسودات مش بتظهر هنا.

| الطريقة | المسار | ملاحظات |
|---|---|---|
| GET | `/` | الطابور (الأقدم أول): `status` و`country_id` و`specialty_id` و`search` (عنوان/عميل/موبايل)، و`pagination.counts` لكل حالة |
| GET | `options` | الدول والتخصصات للفلاتر |
| GET | `{uuid}` | المشروع مع `client` و`missing` و`can_decide` و`timeline` (فيها `reason` و`actor`) والملفات (`attachments[].path`) |
| PUT | `{uuid}` | تعديل المراجع لمشروع **قيد المراجعة** بنفس حقول وفحوصات نموذج العميل؛ كل تعديل حدث `project.edited_by_admin` فيه أسماء الحقول ومتسجّل في الـ audit |
| POST | `{uuid}/publish` | نشر: المشروع لازم يكون كامل وجوه حدود الميزانية (نفس أخطاء الإرسال)، ويتحط `published_at` |
| POST | `{uuid}/request-changes` | `reason` إجباري |
| POST | `{uuid}/reject` | `reason` إجباري. **المرفوض مش نهائي:** العميل يقرا السبب (`status_reason`) ويعدّل ويبعته تاني من `submit` |
| GET | `{uuid}/attachments/{media}` | فتح ملف العميل للمراجع (بالتوكن، `no-store`) |

قرار تاني على مشروع مش «قيد المراجعة» = `freelance_project_invalid_state` (409). كل قرار بيمشي من `ProjectStateMachine` والعميل بيتبلّغ لحظياً وبالـ push بالسبب (`freelance.project.published` / `.rejected` / `.changes_requested`).

## تصفح المشاريع للمحترف (FL-206 / FL-207)

على `freelance_api` تحت `/api/freelance/v1/projects` (ورا مفتاح `projects` لدولة المحترف). مفتوح للمحترف غير الموثّق كمان (التقديم هو اللي بيتقفل ببوابة التوثيق، FL-301).

| الطريقة | المسار | ملاحظات |
|---|---|---|
| GET | `/` | **المنشور والعام من دولة المحترف بس**، الأحدث أول. فلاتر: `q`، `specialty_id` (الأب بيجيب شغل أولاده)، `budget_min_minor` / `budget_max_minor` (يتقاطع مع نطاق المشروع)، `duration_max_days`، `favorites=1`. `page` و`per_page` (15، لحد 50) |
| GET | `{uuid}` | الصفحة: الوصف كامل و`attachments_count`. أي مشروع مش منشور أو خاص أو من دولة تانية = 404 |
| POST / DELETE | `{uuid}/favorite` | حفظ / إزالة (خاص بالمحترف). بيرجّع المشروع مع `is_favorite` |

كل مشروع فيه `uuid` و`title` و`specialty` و`skills` و`budget{type,min_minor,max_minor,currency}` و`duration_days` و`published_at` و`is_favorite`، والقايمة فيها `summary` (160 حرف). **مفيش اسم العميل ولا موبايله، ومفيش الملفات** (بتظهر بعد قبول العرض).

**نطاق التخصص:** `scope=mine|all`. من غيره: لو المحترف اختار تخصصات يشوف مشاريع تخصصاته وفروعها بس (اختيار فرع لوحده مايجيبش شغل أبوه)، ولو مفيش تخصصات يشوف الكل. `specialty_id` في الفلتر بيغلب، و`favorites=1` بيتجاهل النطاق. الرد فيه `pagination.scope` و`pagination.has_specialties`.

## نافذة استقبال العروض (FL-204)

المشروع المنشور بياخد `offers_deadline_at = وقت النشر + offers.window_days` (من سياسة دولة وتخصص المشروع **وقت النشر**، وتغيير السياسة بعدها ما بيمسش مشروع مفتوح). الإغلاق علامة لوحدها: المشروع يفضل `published` والعروض بس هي اللي بتقف.

| الطريقة | المسار | ملاحظات |
|---|---|---|
| POST | `mobile/v1/freelance/projects/{uuid}/offers/close` | العميل بيقفل بإيده. `freelance_project_offers_not_open` لو مقفول أصلاً |
| POST | `mobile/v1/freelance/projects/{uuid}/offers/reopen` | نافذة جديدة. لحد `freelance.projects.max_reopens` (3) مرات للعميل (`freelance_project_reopen_limit`)، و`freelance_project_offers_already_open` لو لسه مفتوح |
| POST | `admin/v1/freelance-projects/{uuid}/offers/close` و`/offers/reopen` | للأدمن (`freelance-projects.update`)، وإعادة الفتح من غير حد |

كل رد مشروع فيه `offers{accepting, deadline_at, closed_at, close_reason (deadline|client|admin)}`، وللعميل كمان `can_close` و`can_reopen` و`reopens_left`. المحترف بيشوف المشروع المقفول في القايمة بعلامة (مش بيختفي) ومايقدرش يقدّم عليه. الأمر `freelance:close-expired-projects` كل ساعة بيقفل اللي مهلته خلصت ويبلّغ العميل (`freelance.project.offers_closed`)، لكن `accepting` بيحسب الوقت لحظة السؤال فمفيش فجوة. **منع العرض بعد المهلة:** `ProjectOffersWindow::assertAccepting()` (`freelance_project_offers_closed` 422) هتتنادى من تقديم العرض FL-301.

## العروض (FL-301)

**المحترف** (`freelance_api`، ورا مفتاحي `projects` و`bidding`):

| الطريقة | المسار | ملاحظات |
|---|---|---|
| GET | `projects/{uuid}/offer-preview[?price_minor=]` | `blocked` (null أو `{code, message}`) + `quota{enabled, limit, used, left}` + `min_price_minor` + `breakdown{price_minor, commission_minor, net_minor, currency}` |
| POST | `projects/{uuid}/offers` | multipart: `price_minor`، `duration_days`، `details` (30–3000)، `files[]` لحد 5 (نفس أنواع وحجم ملفات المشروع) |
| GET | `projects/{uuid}/my-offer` | `{offer: … أو null}` |
| GET | `offers` | عروضي، الأحدث أول (مع `project{uuid,title}`)، `page` و`per_page` (15) |
| GET | `offers/{uuid}/attachments/{id}` | ملف من عرضي |

**ترتيب الفحص:** حساب موثّق وبيستقبل شغل (`freelance_verification_required` 403 / `freelance_unavailable`) ← المشروع بيستقبل عروض (`freelance_project_offers_closed`) ← مفيش عرض قبل كده (`freelance_offer_already_sent` 409) ← المشروع مش مكتمل (`offers.max_per_project`، `freelance_project_offers_full`) ← الحصة الشهرية (`offers.free_monthly_quota` لو مفتاح `free_offer_quota` مفتوح، `freelance_offer_quota_exhausted` و`data.limit`). السعر حر بس مش أقل من حد ميزانية الدولة/التخصص (`freelance_offer_below_min`). العمولة والصافي بيتحسبوا وقت الإرسال ويتحفظوا مع العرض. العرض اللي بيكمّل العدد بيقفل المشروع (`close_reason = max_offers`). العميل بيتبلّغ (`freelance.offer.received`).

**العميل** (`user_api`): `GET mobile/v1/freelance/projects/{uuid}/offers` (العروض مع المحترف: الاسم والعنوان والصورة وموثّق ولا لأ — من غير صافي ربحه، `page` و`per_page` (15)؛ الترتيب ثابت بين الصفحات مهما كان `sort`) و`GET …/offers/{offer}/attachments/{id}`. رد المشروع فيه `offers.count`.

## تعديل العرض وسحبه (FL-302)

على `freelance_api` (ورا `projects` و`bidding`):

| الطريقة | المسار | ملاحظات |
|---|---|---|
| PUT | `offers/{uuid}` | `price_minor` و`duration_days` و`details` (اللي يتبعت بس هو اللي يتغيّر). نفس فحوصات العرض الجديد، و`freelance_offer_unchanged` لو مفيش تغيير. بيتحفظ اللي قبله كنسخة، والعمولة والصافي بيتحسبوا تاني، والعميل بيتبلّغ (`freelance.offer.updated`) |
| POST | `offers/{uuid}/withdraw` | سحب. قبل ما العميل يشوف العرض الحصة المجانية بترجع. العرض المسحوب بيختفي عن العميل ومش بيتحسب في حد عروض المشروع، ومايتبعتش تاني على نفس المشروع |
| GET | `offers/{uuid}/revisions` | اللي كان مكتوب قبل كل تعديل (`number` و`price_minor` و`duration_days` و`details` و`net_minor` و`at`)، الأقدم أول |

التعديل والسحب بس لحد ما العميل يقبل أو يرفض (`submitted`/`viewed`/`shortlisted`) وطول ما المشروع منشور، وإلا `freelance_offer_not_editable` (409). مسموح حتى بعد ما نافذة العروض تقفل (العرض اتقدّم في وقته). رد العرض للمحترف فيه `can{edit, withdraw}` و`revisions`، وللعميل `edited`.

## متابعة حالة العرض (FL-303)

**الحالات:** `submitted` ← `viewed` / `shortlisted` / `rejected`؛ `viewed` ↔ `shortlisted`؛ `viewed`/`shortlisted` ← `rejected` / `accepted`. أي انتقال غير مسموح = `freelance_offer_invalid_state` (409).

- **اتشاف:** لما العميل يفتح قايمة العروض (`GET mobile/v1/freelance/projects/{uuid}/offers`) كل عرض لسه `submitted` بيبقى `viewed` **مرة واحدة** (`viewed_at`، حدث `offer.viewed`، والمحترف بيتبلّغ مرة). القرار على عرض بيعتبر برضه إنه شافه.
- **قرارات العميل** (`user_api`): `POST projects/{uuid}/offers/{offer}/shortlist` و`/reject` (`reason` اختياري بيوصل للمحترف في `status_reason`) و`/choose` (مشروع منشور بس، وعرض واحد بس `freelance_offer_already_chosen`). `choose` هنا انتقال حالة بس؛ رفض الباقي والاتفاق والدفع في FL-309 / FL-401.
- **إشعار المحترف** لحظي وpush: `freelance.offer.viewed` / `.shortlisted` / `.rejected` / `.accepted` (فيه `data.project`).
- **رد العرض للمحترف** فيه `status_reason` (لو اترفض) و`timeline[{type, to, at}]`.

## مقارنة العروض واختيار عرض (FL-305 + FL-309)

كل الـ endpoints تحت `mobile/v1/freelance/projects/{uuid}` (`user_api`، صاحب المشروع بس):

- `GET offers?sort=newest|cheapest|fastest|completeness&status=all|shortlisted|…&page=&per_page=` (15 في الصفحة؛ «اتشاف» بيتعلّم على الصفحة الأولى بس) — كل عرض فيه `freelancer{completeness, years_of_experience, portfolio_count, specialties, skills, matching_skills, project_skills_count, rating, reviews_count, completed_projects}` (التقييم والمنجز `null` لحد FL-701/703) و`can{shortlist, reject, choose}` و`client_cost{price_minor, client_fee_minor, total_minor, currency}` (رسوم العميل من السياسة لو مفتاح `client_fee` شغال).
- `GET offers/compare?ids=a,b[,c]` — من 2 لـ 3 عروض بترتيب الاختيار + `best{price, duration, completeness, skills}`. عدد تاني = `freelance_offer_compare_count` (422)؛ عرض مش في المشروع = 404.
- `GET offers/{offer}` — عرض واحد بنفس الشكل.
- `GET offers/{offer}/freelancer` — بروفايل المحترف للعميل: نبذة، خبرات، شهادات، معرض الأعمال (روابط صور موقّعة)، لغات، مدينة. **من غير تليفون ولا إيميل.**
- `POST offers/{offer}/choose` — transaction واحدة: العرض `accepted` (`accepted_at`)، الباقي `rejected` تلقائي (حدث فيه `auto: another_chosen`، والمحترف بيقرا «العميل اختار عرض تاني» في `status_reason`)، العروض بتتقفل (`close_reason = chosen`) والمشروع `awarded` (`awarded_at`، حدث `project.awarded`). اختيار تاني = `freelance_offer_already_chosen` (409)؛ مشروع مش منشور = `freelance_project_invalid_state`.
- حالة المشروع الجديدة `awarded`: `published → awarded → cancelled` (FL-401 هيضيف الاتفاق). المحترف لسه يقدر يفتح مشروع قدّم عليه بعد ما اتختار عرض.

- `POST offers/{offer}/unshortlist` — يشيل العرض من القايمة المختصرة ويرجعه `viewed` (من غير إشعار للمحترف)؛ `can.unshortlist` بيقول إمتى الزرار يظهر. مرفوض (409) لو العرض مش في القايمة.

## المراسلة قبل التعاقد (FL-308)

- `POST mobile/v1/freelance/projects/{uuid}/offers/{offer}/chat` (العميل، مفتاح `chat`) — يفتح أو يلاقي الغرفة. ممكن بس لعرض `submitted|viewed|shortlisted|accepted` على مشروع `published|awarded`، غير كده `freelance_chat_not_available` (409). العميل بس هو اللي يبدأ؛ المحترف بيلاقيها في `offer.chat`.
- الرسايل بنفس endpoints FL-006: `…/chat/conversations/{uuid}[/messages|/read]` (العميل تحت `mobile/v1/freelance`، المحترف تحت `freelance/v1`). الغرفة بترجع `guarded` و`project{uuid,title}`.
- قبل الاتفاق (`published|awarded`): أي رسالة فيها رقم موبايل (بأرقام عربي/هندي وبمسافات ونقط) أو إيميل أو رابط أو `@handle` بترجع `freelance_chat_contact_blocked` (422) ومبتتبعتش. التواريخ والأسعار مش بتتعامل كرقم موبايل. الملفات مش بتتفحص.
- الغرفة بتبقى قراءة بس (`freelance_chat_closed` 409) لما العرض يترفض/يتسحب/يتختار غيره أو المشروع يتلغي.
- عرض العميل: `chat{uuid, unread, can_write}|null` و`can.chat`؛ عرض المحترف: `chat{…}|null`.

## فحص العرض وقوالب العروض (FL-304)

- `POST projects/{uuid}/offer-check` (`price_minor?`, `duration_days?`, `details?`, `files?`) ← `checks[{code, level, message}]`. `level=error`: `contact` (السيرفر هيرفض). `level=warning`: `details_short` (أقل من 150 حرف)، `no_files`، `price_high` (أكتر من 1.5× أعلى الميزانية)، `price_low` (أقل من نص أقل الميزانية)، `duration_long` (أطول من مدة المشروع).
- إرسال أو تعديل عرض فيه رقم موبايل/إيميل/رابط/handle ← `freelance_offer_contact_blocked` (422).
- القوالب: `GET|POST offer-templates`، `PUT|DELETE offer-templates/{id}` (`title` لحد 80، `details` بنفس حدود العرض). بتاعتي بس، حد أقصى 10 (`freelance_offer_templates_full`)، ومن غير بيانات تواصل.

## أسئلة موحدة للمتقدمين (FL-307)

- العميل: `questions[]` (لحد 5، كل سؤال لحد 300 حرف) في `POST|PUT mobile/v1/freelance/projects[/{uuid}]` طول ما المشروع قابل للتعديل. المشروع بيرجع `questions`.
- المحترف: المشروع (`GET freelance/v1/projects/{uuid}`) فيه `questions`. العرض (`POST projects/{uuid}/offers` و`PUT offers/{uuid}`) بياخد `answers[]` بنفس ترتيب الأسئلة، إجابة لكل سؤال (حرفين على الأقل، لحد 1000). ناقص ← `freelance_offer_answers_required` (422)؛ فيها بيانات تواصل ← `freelance_offer_contact_blocked`.
- العرض للطرفين فيه `answers[{question, answer}]`، والمقارنة بتعرضهم جنب بعض.

## المفضلة والقائمة المختصرة (FL-306)

- القائمة المختصرة: `shortlist` / `unshortlist` وفلتر `status=shortlisted` (FL-303/305).
- المحترفين المفضّلين للعميل: `GET mobile/v1/freelance/favorites` (`page`/`per_page`)، `POST|DELETE favorites/{uuid}` — بس لمحترف قدّم للعميل عرض قبل كده (غير كده 404). العروض والبروفايل فيهم `is_favorite`. القائمة مفيهاش تليفون ولا إيميل.
- **قوايم المحترفين للعميل (FS-103):** تحت `mobile/v1/freelance/talent-lists` (ميزة `projects`): `GET /[?specialty_id=]` → `{lists[{uuid,name,specialty,count}], max_lists:20}` · `POST /` `{name≤80, specialty_id?}` · `GET|PUT|DELETE {uuid}` · `POST {uuid}/members` `{freelancer:uuid, note≤200?}` · `PUT {uuid}/members/{freelancer}` `{note?, remind_in_days?: 30|60|90|180|null}` · `DELETE {uuid}/members/{freelancer}` (كلهم بيرجّعوا شكل `show`: القايمة + `members[{uuid,name,headline,avatar_url,verified,completeness,note,remind_at,worked_together,added_at}]` + `remind_options`) · `GET containing/{freelancer}` → `{lists:[uuid]}` (للقلب في البروفايل) · `POST {uuid}/invite` `{project, freelancers[]?}` → `{invited, skipped[{uuid,reason}]}` بنفس قواعد `ProjectInvitations` (مشروع **خاص ومنشور** بتاعي بس). المحترف المحفوظ لازم يكون موثّق. حد أقصى 20 قايمة و100 محترف في القايمة (`freelance_talent_lists_limit` / `freelance_talent_list_full`). **التذكير بيتبعت للعميل بس وبموافقته** (لما يحدد `remind_in_days`): مرة واحدة، حدث `talent.reminder` (`freelance:talent-reminders` كل ساعة). المحترف مبيشوفش غير **عدد** القوايم اللي هو فيها: `data.saved{lists,new}` في إحصائياته (`GET insights`) — مفيش اسم قايمة ولا عميل.

## الاتفاق الإلكتروني (FL-401)

**الحالات:** `draft` ← `pending_approval` ← `active`؛ أو `declined` / `cancelled` / `expired`. المشروع: `awarded` ← `in_progress` (لما الاتفاق يبقى ساري) أو يرجع `published`.

- **العميل** (`mobile/v1/freelance/projects/{uuid}`): `GET|PUT agreement` (`scope` مطلوب، `includes`، `excludes`)، `POST agreement/send` (= موافقة العميل، والمحترف عنده 7 أيام)، `POST agreement/cancel`. التعديل بعد الإرسال بيرجّعه `draft`.
- **المحترف** (`freelance/v1/projects/{uuid}`): `GET agreement` (مش ظاهر وهو مسودة)، `POST agreement/approve`، `POST agreement/decline {reason?}`.
- **الرد:** `money` للعميل `{price_minor, client_fee_minor, total_minor}` وللمحترف `{price_minor, commission_minor, net_minor}`؛ `terms{review_days, free_revisions, auto_approve}` من لقطة السياسة المجمّدة؛ `can{edit,send,cancel}` أو `can{approve,decline}`؛ `expires_at`.
- **بعد الموافقة:** الاتفاق مايتعدلش (`freelance_agreement_invalid_state` 409)، المشروع `in_progress`، وفلتر بيانات التواصل في الشات بيتشال.
- **رفض/إلغاء/انتهاء:** المشروع يرجع `published` بمهلة عروض جديدة، العروض اللي اترفضت عشان الاختيار ترجع `viewed`. المشروع (`project.agreement`) وعرض المحترف (`offer.agreement`) فيهم `{uuid, status}`.
- **الإشعارات:** محترف `agreement.received|cancelled|expired`، `project.cancelled`؛ عميل `agreement.active|declined|expired`.
- **سجل الموافقات:** `freelance_project_approvals` (مفيش حذف) — بداية FL-406.

## المراحل (FL-402)

- كل اتفاق ليه مرحلة واحدة على الأقل: مع المسودة بتتعمل «الشغل كله» بكامل السعر.
- العميل: `PUT mobile/v1/freelance/projects/{uuid}/agreement/milestones` بالقايمة كاملة `{milestones: [{title, description?, amount_minor, duration_days?}]}` طول ما الاتفاق قابل للتعديل (بعد الإرسال بيرجّعه `draft`).
- القواعد من لقطة السياسة: المجموع = السعر بالظبط (`freelance_milestones_total`)، العدد ≤ `milestones.max_count` (`freelance_milestones_too_many`)، ولو أكتر من مرحلة كل واحدة ≥ `milestones.min_amount_minor` (`freelance_milestone_too_small`؛ الافتراضي 5000 = 50.00).
- الاتفاق بيرجع `milestones[{uuid, position, title, description, amount_minor, duration_days, status}]` + للعميل `client_fee_minor, total_minor` وللمحترف `commission_minor, net_minor`، و`milestone_rules{max_count, min_amount_minor}` و`can.split`.
- لما الاتفاق يبقى ساري: المراحل بتتجمّد، وفلوس الاتفاق = مجموع المراحل. الحالة `unfunded` لحد FL-403 / FL-501.

## حالات المرحلة (FL-403)

`unfunded → funded → in_progress → delivered → approved`؛ `delivered ↔ revision`؛ `disputed → approved | revision | cancelled`؛ `unfunded | funded | in_progress → cancelled`. أي انتقال غلط ← `freelance_milestone_invalid_state` (409)، ومرحلة قبل دورها ← `freelance_milestone_out_of_turn` (409). كل انتقال حدث في `freelance_milestone_events`.

- المحترف: `POST freelance/v1/projects/{uuid}/milestones/{milestone}/start` (من `funded`)، `/deliver {note}` (من `in_progress` أو `revision`).
- العميل: `POST mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/approve` و`/revision {reason}` (من `delivered`)، `/cancel` (لو `unfunded` بس).
- كل مرحلة في الاتفاق فيها `status, delivery_note, revision_reason, revisions_used, delivered_at, approved_at, can{…}` (`can` فاضية لو الاتفاق مش ساري).
- التمويل (`funded`) بيعمله FL-501 عن طريق `MilestoneWork::fund`.

## تمويل المرحلة (FL-501 + FL-502)

- `POST mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/fund` (العميل، مفتاح `wallet_payment`): بيحجز **قيمة المرحلة + رسم العميل** من محفظة العميل في دولة المشروع (spend-only الأول ثم withdrawable)، والمرحلة تبقى `funded` والمحترف يتبلّغ (`payment.held`).
- الحجز `reference_type = freelance_milestone` و**من غير تاريخ انتهاء** (`expires_at = null`) فصمام الأمان مش بيفكّه (FL-502).
- رصيد ناقص ← `freelance_wallet_insufficient` (422) و`data{needed_minor, available_minor, shortfall_minor, currency}`؛ عملة مختلفة ← `freelance_wallet_currency`.
- التكرار آمن: المرحلة بتتقفل، والمحجوزة أصلاً بترجع زي ما هي، ومفتاح كل حجز `freelance:milestone:{uuid}:{bucket}`. بالترتيب (`freelance_milestone_out_of_turn`).
- الاتفاق بيرجع `wallet_available_minor` (للعميل)، وكل مرحلة `held_minor` و`can.fund`.
- مشروع فيه فلوس محجوزة مينفعش يتلغي (`freelance_project_has_funded_work`)؛ الاسترداد في FL-506، والتحرير للمحترف في FL-503.

## التحرير والاعتماد التلقائي وقفل المشروع (FL-503 + FL-504 + FL-408)

- **الاعتماد = الدفع** (`POST …/milestones/{milestone}/approve`): في معاملة واحدة: حجز العميل (القيمة + رسم العميل) بيتلتقط `service_payment`، الصافي (القيمة − العمولة) بيدخل محفظة المحترف withdrawable `service_earning`، ورسم العميل والعمولة بيتقيدوا دخل في الدفتر (`freelance_client_fees`، `freelance_commissions`). لو الحجز مش موجود ← `freelance_milestone_not_funded` (409) والمرحلة تفضل `delivered`. المحترف بيتبلّغ `payment.released`.
- **الاعتماد التلقائي:** `freelance:release-due` كل ساعة: مرحلة `delivered` عدّى عليها `review.days` (من لقطة الاتفاق) و`review.auto_approve` شغال ← تتعتمد وتتحرر (`milestone.auto_approved` للعميل). تنبيه قبلها بيوم مرة واحدة (`milestone.review_due`). كل مرحلة فيها `auto_approve_at`.
- **قفل المشروع:** لما كل المراحل `approved|cancelled` ← الاتفاق `completed` (`completed_at`)، المشروع `closed`، الشات قراءة بس، وإشعار `project.completed` للطرفين.
- كل مرحلة: `released_at`، وللمحترف `paid_minor`.

## إنهاء مرحلة وجولات التعديل (FL-506 + FL-407)

- **ممولة وما بدأتش:** العميل `POST mobile/v1/freelance/projects/{uuid}/milestones/{m}/cancel` أو المحترف `POST freelance/v1/projects/{uuid}/milestones/{m}/withdraw` ← المحجوز كله يتفك ويرجع للعميل (`refunded_minor`)، وإشعار `milestone.called_off` للطرف التاني.
- **بدأ فيها شغل (`in_progress` / `revision`):** نفس الطلبين بيبعتوا **طلب إنهاء**، والطرف التاني يرد خلال 3 أيام بـ `.../cancel-accept` أو `.../cancel-reject` (عند الاتنين). عند الموافقة: المحترف ياخد نسبة `cancellation.started_freelancer_bp` من المرحلة ومن رسم العميل ومن العمولة (الصافي في محفظته `paid_minor`)، والباقي يرجع للعميل (`refunded_minor`)، والرسم والعمولة يتقيدوا على الجزء المستخدم بس. المرحلة `cancelled`.
- **مفتاح السياسة الديناميكي:** `cancellation.started_freelancer_bp` (نسبة، افتراضي 30%) من لوحة «الأسعار والقواعد» تحت «إلغاء شغل اتبدأ»، لكل دولة/تخصص، وبيتجمّد مع الاتفاق.
- **مسلّمة أو عليها نزاع:** مفيش إنهاء من هنا. إلغاء المشروع بيفك المراحل الممولة غير المبدوءة وبيرفض لو فيه مرحلة بدأت/اتسلّمت (`freelance_project_has_funded_work`).
- **FL-407:** `review.free_revisions` (من لقطة الاتفاق) بيتطبق: بعد الجولات المجانية `POST …/revision` ← `freelance_revisions_exhausted` (409) والمحترف يتبلّغ؛ كل مرحلة فيها `revisions_max` و`revisions_left` و`can.revision`.

## النزاعات — الدفعة 1: الباك (FL-704 / FL-507 / FL-705)

- **الفتح** (الطرفين، مفتاح `disputes`): `POST projects/{uuid}/milestones/{m}/dispute {reason, details}` على مرحلة `funded | in_progress | delivered | revision` في اتفاق ساري. الأسباب: `not_as_agreed, not_delivered, late, quality, out_of_scope, no_response, other`. المرحلة بتبقى `disputed` (متجمّدة: لا اعتماد ولا تلقائي ولا تعديل ولا إنهاء، والمشروع ما يتلغيش) والفلوس تفضل محجوزة. نزاع واحد للمرحلة.
- **الأدلة:** `POST projects/{uuid}/disputes/{d}/evidence` (multipart `body` + `file?`)، والملفات خاصة `GET …/disputes/{d}/files/{media}`. `GET projects/{uuid}/disputes/{d}` للطرفين بس (أي محترف تاني = 404).
- **القرار (الأدمن):** `POST admin/v1/freelance-disputes/{uuid}/decide {decision: refund|release|split, freelancer_percent?, note}` بصلاحية `freelance-disputes.update`. القرار الأول بيستنى `disputes.appeal_days` (افتراضي 3) وبعدين ينفذ (`freelance:execute-disputes` كل ساعة).
- **الاعتراض:** أي طرف مرة واحدة قبل التنفيذ `POST …/disputes/{d}/appeal {reason}` ← موظف **تاني** يقرر (`freelance_dispute_same_admin`) وقراره نهائي وبينفذ فوراً.
- **التنفيذ:** `release` = اعتماد وتحرير (FL-503)، `refund` = فك الحجز كله، `split` = نسبة المحترف من القيمة والرسم والعمولة والباقي للعميل. مرة واحدة.
- **مفاتيح السياسة:** `disputes.response_days` (5) و`disputes.appeal_days` (3) من لوحة «الأسعار والقواعد».
- **الأدمن:** `GET admin/v1/freelance-disputes[?status=&country_id=]` (اللي محتاج قرار الأول، `pagination.counts`)، `GET …/{uuid}` (المرحلة وتسليمها وتاريخها، الاتفاق، الشات، الأدلة).
- كل مرحلة فيها `dispute{uuid,status}` و`can.dispute`.

## أرباح المحترف وفترة التعليق (FL-505 + FL-509)

- كل صافي للمحترف (اعتماد مرحلة، إنهاء بنسبة، قرار نزاع) بيتسجل في `freelance_earnings`. `earnings.hold_days` من لقطة الاتفاق: صفر (الافتراضي) = في المحفظة فوراً؛ أكتر = `pending` لحد `release_at`، و`freelance:release-earnings` كل ساعة بيحطه في المحفظة مرة واحدة. دخل DORR بيتقيد عند الاعتماد.
- إشعارات: `payment.pending` (المبلغ والتاريخ) عند الاعتماد مع تعليق، `payment.available` لما يتاح، و`payment.released` لما يكون فوري.
- `GET freelance/v1/earnings` ← `{currency, available_minor, pending_minor, next_release_at, expected_minor, earned_minor, recent[{kind, amount_minor, status, project, milestone, release_at, released_at}]}`. `expected_minor` = صافي المراحل الممولة اللي لسه ما اتعتمدتش.

## تعديل الاتفاق بعد ما بدأ (FL-404 + FL-405)

- **الطلب** (الطرفين): `POST projects/{uuid}/agreement/changes {type, …, reason?}`. الأنواع:
  - `extend_days {days, milestone?}`: أيام زيادة للاتفاق أو لمرحلة.
  - `scope {scope?|includes?|excludes?}`: نص الشغل.
  - `milestone_price {milestone, amount_minor}`: لمرحلة `unfunded` بس (الممولة ← `freelance_change_part_funded`)؛ الرسم والعمولة والصافي بيتحسبوا تاني من اللقطة، وسعر الاتفاق بيتحدث.
  - `add_milestone {title, amount_minor, duration_days?}`: مرحلة جديدة `unfunded` (الشغل الزيادة).
- **الرد** (الطرف التاني): `POST …/agreement/changes/{change}/accept | reject`؛ اللي طلب يقدر `withdraw`. المهلة `changes.response_days` (افتراضي 3، من الداشبورد)، وبعدها الطلب بيظهر `expired`.
- **القواعد:** طلب واحد مستني لكل مرحلة (أو للاتفاق) `freelance_change_pending`؛ مفيش على مرحلة عليها نزاع أو طلب إنهاء `freelance_change_not_allowed`؛ النزاع بيلغي طلبات المرحلة؛ حد 5 مقبولة `freelance_changes_limit`. مفيش فلوس بتتحرك.
- `GET …/agreement/changes`، والاتفاق نفسه فيه `changes[{uuid, type, status, mine, milestone, payload, reason, respond_by, can{accept, reject, withdraw}}]`.
- **طلبات التعديل v2 (FS-101):** `POST …/agreement/changes` بيقبل كمان `files[]` (multipart، حتى 3 ملفات × 10MB؛ `freelance_change_too_many_files`)، والملف بيتنزّل من `GET …/agreement/changes/{change}/files/{media}` للطرفين. كل عنصر في القائمة بقى فيه: `addendum_no` (رقم الملحق لما يتقبل)، `applied_version` (نسخة الاتفاق بعد التعديل)، `effect` (`price_minor` / `duration_days` / `milestones` كـ `[قبل, بعد]` للي اتغير بس) و`files[{id,name,mime,size,path}]`. وبيتبعت **تذكير مرة واحدة** للي لازم يرد قبل ما الطلب يخلص بـ 24 ساعة (`freelance:change-reminders` كل ساعة، حدث `change.reminder`).
- **صحة المشروع (FS-106):** `data.health` جوه الاتفاق (`GET …/agreement` للطرفين) = `null` لو الاتفاق مش شغال، وإلا `{state: healthy|at_risk|delayed|blocked, reasons[{code, level, mine, data?{part,days,due_at}}], next_step{code, mine}|null}`. القواعد (بدون AI، من مواعيد وسجلات بس): `dispute_open`/`part_unfunded` (بعد 72 ساعة من غير تمويل لما مفيش شغل تاني) = **blocked**؛ `part_overdue` (بداية المرحلة + مدتها عدّت) = **delayed**؛ `part_due_soon` (خلال 48 ساعة)، `part_not_started` (ممولة 48 ساعة ومابدأتش)، `review_waiting` (تسليم من غير مراجعة 72 ساعة)، `report_late`، `change_waiting` (طلب تعديل من غير رد 48 ساعة) = **at_risk**. `mine` = الطرف ده هو اللي عليه الخطوة؛ `next_step` = أول خطوة عليه أو اللي مستنيه. **تنبيه مرة واحدة** لما الحالة تسوء (`freelance:health-alerts` كل ساعة، أحداث `health.at_risk|delayed|blocked`): at_risk للطرف المطلوب منه بس، و delayed/blocked للطرفين. التحسّن بيتسجّل من غير إشعار. مفيش أي أثر مالي أو على الدرجات.

## التقييم المتبادل وإحصائيات المحترف (FL-701 + FL-703)

- `GET|POST projects/{uuid}/review` (الطرفين؛ الـ POST بمفتاح `ratings`): `{scores: {criterion: 1..5}, comment?}`. معايير العميل للمحترف: `quality, timeliness, communication, professionalism`؛ معايير المحترف للعميل: `clarity, responsiveness, payment, conduct`. متاح بس بعد ما الاتفاق يبقى `completed` ولمدة `ratings.window_days` (افتراضي 14) ← غير كده `freelance_review_closed`؛ مرة واحدة `freelance_review_exists`.
- الرد: `{criteria, can_rate, rate_until, mine, theirs, theirs_waiting}` — `theirs` بيظهر بس لما الاتنين يقيّموا أو المدة تخلص (`freelance:publish-reviews` كل ساعة). الاتفاق نفسه فيه `review`.
- **الإحصائيات** (في العروض والمقارنة وبروفايل المحترف للعميل): `rating` (المتوسط من 3 تقييمات، غير كده null)، `reviews_count`، `completed_projects`، `completion_rate`، `on_time_rate`، `similar_projects`؛ والبروفايل فيه `reviews[]` و`rating_criteria`.
- `GET freelance/v1/reviews` للمحترف: نفس الأرقام + `latest[]`.
- تعديلات FL-405: طلب على مرحلة اتغيّرت قبل الرد (اتمولت/اتسوّت/نزاع) بيتقفل `cancelled` لما الطرف التاني يحاول يقبله، واللي طلب بيتبلّغ (`change.cancelled`).

## سجل الموافقات (FL-406)

- `GET projects/{uuid}/agreement/trail` (العميل: كل اتفاقات مشروعه؛ المحترف: اتفاقه بس، غير كده 404) — الأقدم الأول: `{id, action, label, actor (client|freelancer|admin|system), mine, version, details, at}`.
- الأدمن: `GET admin/v1/freelance-projects/{uuid}/trail` (صلاحية `freelance-projects.view`) بنفس الشكل + `actor_id` و`agreement_id`؛ وملف النزاع فيه `trail`.
- الخطوات: `agreement.{milestones,sent,withdrawn,approved,declined,expired,cancelled,completed}`، `change.{requested,accepted,rejected,withdrawn,cancelled}`، `milestone.{funded,delivered,revision,approved,auto_approved,cancelled,disputed,cancel_requested,cancel_rejected}`، `dispute.{decided,appealed,final_decided,resolved}`، `review.rated`. الصف مايتعدلش ومايتمسحش.

## الإيصالات (FL-511)

- **الإبلاغ عن رسالة (FL-709):** `POST chat/messages/{id}/report {reason, note?}` (البريفيكسين، throttle 20/دقيقة): `reason` من `abuse · harassment · blackmail · contact_info · spam · other` (غير كده 422)، `note` ≤ 500. المسموح: رسالة **الطرف التاني** في غرفة أنا فيها (غرفة مش بتاعتي = 404)؛ رسالتي أو سطر نظام = 422 `report_not_allowed`، رسالة اتسحبت = 422 `report_message_gone`، تاني مرة = 409 `report_already`. النص بيتحفظ وقت البلاغ والطرف التاني مش بيتبلّغ. الأدمن (`freelance-message-reports`): `GET admin/v1/freelance-message-reports?status=open|upheld|dismissed&reason=` (فيه `pagination.counts`) و`POST …/{uuid}/decide {decision: dismiss|uphold, hide_message?, note?}` (مرة واحدة، تاني = 409)؛ `uphold` + `hide_message` بيخلّي الرسالة «محذوفة» في الغرفة. **القرار مش بيقيّد أي حساب** (FL-708).
- **الاعتراض على التقييم (FL-706):** `GET {uuid}/review` بقى فيه `can_object` (التقييم اللي عني اتنشر، ماعترضتش قبل كده، وفي أقل من 14 يوم)، `object_until`، `objection` (`{reason,note,status,decision_note}` أو null)، `objection_reasons`، و`mine.removed`؛ و`theirs` بترجع null لو التقييم اتشال، و`theirs.comment_hidden` لو التعليق اتخفى. `POST {uuid}/review/objection {reason: abusive|false|unfair|other, note ≥5 ≤500}` (البريفيكسين، throttle): قبل النشر أو بعد 14 يوم أو مرة تانية = 409 (`objection_*`)، وبيرجّع نفس شكل `review`. الأدمن (`freelance-reviews`): `GET admin/v1/freelance-reviews/objections?status=open|rejected|comment_hidden|rating_removed` (فيه `pagination.counts`) و`POST …/objections/{uuid}/decide {decision: reject|hide_comment|remove_rating, note?}` (مرة واحدة، تاني = 409). `hide_comment` بيخلّي النجوم تتحسب والكلام يتخفى، و`remove_rating` بيطلّع التقييم من المتوسط والملف. بيوصل إشعار `review.objection_decided` للي اعترض و`review.moderated` للي قيّم (لو اتغيّر حاجة). **القرار مش بيقيّد أي حساب** (FL-708).
- **استبيان الرضا (FL-702):** `GET {uuid}/survey` → `{can_answer, answer_until, reasons[], not_satisfied_max: 3, mine}`؛ `POST {uuid}/survey {score 1..5, reasons?[], comment? ≤500}`: مرة من كل طرف، خلال 30 يوم من نهاية المشروع (اتكمل، أو اتلغى بعد ما بدأ) وإلا 409 (`survey_closed` / `survey_exists`)؛ درجة 3 أو أقل من غير سبب = 422 `survey_reasons_required`. الأسباب: quality · late · communication · price · scope · payment · platform · other. الأدمن: `GET admin/v1/freelance-reviews/satisfaction?from=&to=` (`freelance-reviews.view`): العدد والمتوسط والتوزيع وأسباب عدم الرضا لكل طرف وآخر الردود الضعيفة.
- **الإنذارات والقيود والتعليق (FL-708):** `GET sanctions` (البريفيكسين) → `{restrictions: {chat, work}, items: [...]}` (آخر سنة): كل واحدة `{uuid, type: warning|chat_limit|work_limit|suspension, state: active|lifted|expired, reason, blocks: [chat|work], starts_at, ends_at (null = لحد ما يترفع), can_appeal, appeal_until, appeal: {status: open|upheld|reduced|lifted, note, decision_note}}`. `POST sanctions/{uuid}/appeal {note 5..1000}` (throttle 10/دقيقة): مرة واحدة خلال 14 يوم من البداية، وإلا 409 `appeal_exists` / `appeal_closed`؛ عقوبة مش بتاعتي = 404. **المنع:** `chat` بيوقف إرسال الرسائل وطلب المقابلات (`403 freelance_restricted_chat`)، و`work` بيوقف العروض الجديدة وقبول الدعوات للمحترف، وإرسال مشروع واختيار عرض ودعوة محترفين للعميل (`403 freelance_restricted_work`)، والاتنين فيهم `data.until`. **مفيش أي قيد بيلمس المحفظة أو السحب أو التسليم أو الموافقة أو النزاعات.**
- **الأدمن (`freelance-sanctions`: view · issue · suspend · appeal):** `GET admin/v1/freelance-sanctions?state=active|lifted|expired&appeal=open&type=&side=&search=` (`pagination.counts`: active, lifted, expired, appeals)؛ `GET …/people?side=&search=`؛ `GET …/person/{side}/{id}` → `{quality, restrictions, sanctions}`؛ `POST freelance-sanctions {side, person_id, type, reason ≥5, days?, source?: manual|message_report|review_objection|dispute, source_ref?}` (القيد محتاج `days` 1..30، التعليق `days` اختياري 1..365 ومحتاج `suspend` وإلا 403 `sanction_suspend_permission`)؛ `POST …/{uuid}/lift {note?}` (تعليق = `suspend`)؛ `POST …/{uuid}/appeal {decision: upheld|reduced|lifted, ends_at? (للتقصير)، note?}` (اللي أصدرها مايقدرش = 403 `appeal_same_admin`؛ التقصير لازم تاريخ في المستقبل وقبل النهاية الحالية = 422 `appeal_reduce_date`). كل إجراء بيتسجل في `audit_logs` وبيبعت إشعار `sanction.issued|lifted|appeal_decided`. **العملاء (FL-707):** `GET admin/v1/freelance-sanctions/clients?risk=low|medium|high&search=&per_page=` (صلاحية `view`) — العملاء اللي بدأوا مشاريع بجودتهم ومخاطرهم (من `freelance_client_risk`، بيتحدّث يوميًا بـ`freelance:refresh-risk` وعند كل تغيير في القيود وعند فتح الشخص)، الأعلى خطورة الأول، و`pagination.counts` بعدد كل مستوى.
- **مؤشر الجودة والمخاطر (FL-707):** في `person` → `quality: {score 0..100|null, level: low|medium|high, reasons: [{code, level, count}], inputs}`؛ الأكواد: `reports_upheld`, `disputes_lost`, `suspensions`, `sanctions`, `cancel_rate`, `account_status`, `not_verified`. `GET admin/v1/freelancers` بقى فيه `quality_score` و`risk_level` وفلتر `risk=low|medium|high` (القيمة بتتحدّث يومياً بـ `freelance:refresh-risk` وبعد أي عقوبة ولما الأدمن يفتح الشخص). الأرقام والأوزان في `config/freelance.php` (`sanctions`, `quality`).
- **سبب الإلغاء وتصنيف المسؤولية (FL-607):** إلغاء جزء بيقبل `reason_code` (واحد من `scope_changed · client_unresponsive · not_delivered · quality · mutual · force_majeure · other`، غير كده 422) و`reason` اختياري ≤ 500: عميل `POST …/milestones/{uuid}/cancel` وإلغاء المشروع `POST projects/{uuid}/cancel`، ومحترف `POST …/milestones/{uuid}/withdraw` (`note` هو النص). التصنيف بيتحسب من السبب: scope_changed/client_unresponsive ← `client`، not_delivered/quality ← `freelancer`، mutual ← `shared`، force_majeure ← `none`، other (أو من غير كود) ← `review`. بيتسجّل في `freelance_cancellation_reasons` لما الإلغاء يتم فعلاً (أو طلب الإلغاء لجزء بدأ شغله) — **قول اللي ألغى، مش حكم**، وللأدمن بس: `GET admin/v1/freelance-reports/cancel-reasons?from=&to=&responsibility=` (صلاحية `freelance-reports.view`) بيرجّع `total` و`by_responsibility` و`by_reason` وآخر الصفوف. **مفيش أي أثر مالي أو عقوبة تلقائية.**
- **تقارير التقدّم (FL-605) — اختيارية:** العميل بيحدد وهو بيكتب الاتفاق `report_every_days` (`PUT projects/{uuid}/agreement`: **3 أو 7 أو 14**، و`0`/`null` = من غير تقارير، غيرهم 422 `freelance_report_days_invalid`)؛ بتتجمّد مع الاتفاق. المحترف يبعت في أي وقت والاتفاق شغال: `POST projects/{uuid}/reports {note (3–2000), percent? (0–100)}` على `freelance/v1`، والاتنين يقروا `GET projects/{uuid}/reports` (على `freelance/v1` و`mobile/v1/freelance`) ← `{summary{every_days,next_due_at,last_at,late,allowed_days}, reports[{uuid,note,percent,created_at}]}` (الأحدث أول، لحد 100). الاتفاق نفسه فيه `reports` (نفس الـ summary) و`can.report` للمحترف. كل تقرير بيبدأ الفترة الجاية (`next_report_due_at = دلوقتي + الفترة`). الأمر `freelance:report-reminders` (كل ساعة): لما الميعاد يجي المحترف بيتفكّر **مرة** (`freelancer.report.due`)، ولو عدّى يوم (`LATE_AFTER_HOURS`) من غير تقرير العميل بيتنبّه **مرة** (`client.report.late`)؛ تقرير جديد بيرجّع التنبيهين. مفيش حظر ولا غرامة على التأخير. إشعار العميل بالتقرير: `client.report.received`. أخطاء: `report_not_active` (409)، `report_note_required`، `report_days_invalid`. العميل مبيبعتش (403)، وغير طرفي الاتفاق 404.
- `GET receipts` (الأحدث الأول، `page`/`per_page` (15)، و`?project={uuid}` لإيصالات مشروع واحد) و`GET receipts/{uuid}` — العميل على `mobile/v1/freelance`، المحترف على `freelance/v1`؛ كل طرف إيصالاته بس.
- الإيصال: `{uuid, number (FR-SA-2026-000123), party, kind, reason, title, project, milestone, currency, amount_minor, fee_minor, total_minor, refunded_minor, issued_at}` + في الواحد: `client, freelancer, reason_text, text` (نص جاهز للمشاركة).
- الأنواع: `funded` (العميل: المحجوز)، `paid` (العميل: اتدفع عند القبول)، `earned` (المحترف: المبلغ والعمولة والصافي)، `refunded` (العميل: رجع كله)، `settled` (العميل: مرحلة اتقفلت بعد الشغل — المدفوع واللي رجع). السبب: `approved · auto_approved · dispute · ended · cancelled`.
- الرقم متسلسل لكل دولة وسنة، بيتكتب في نفس ترانزاكشن الحركة.

## تقرير فلوس المشاريع والمطابقة (FL-512)

- `GET admin/v1/freelance-reports/summary[?country_id=&from=&to=]` (الشهر الحالي افتراضيًا): لكل دولة `held_minor, pending_earnings_minor` (دلوقتي) و`funded_minor, paid_minor, refunded_minor, earned_minor, client_fees_minor, commissions_minor, counts` (في الفترة).
- `GET admin/v1/freelance-reports/reconcile[?country_id=]`: `{checked_at, count, issues[]}` — الأنواع `hold_mismatch, hold_left, receipt_missing, fee_mismatch, commission_mismatch, earning_unpaid`. مفيش حاجة بتتصلح لوحدها.

### المتابعة والمتأخر للموظفين (FL-801 / FL-802)
صلاحية `freelance-projects.view`، للقراءة فقط.
- `GET admin/v1/freelance-oversight/projects[?status=&country_id=&q=&per_page=]` — المشاريع + `pagination.counts` لكل حالة.
- `GET admin/v1/freelance-oversight/offers[?status=&project=uuid&freelancer_id=&per_page=]` — العروض + counts.
- `GET admin/v1/freelance-oversight/agreements[?status=&project=uuid&per_page=]` — الاتفاقات مع `parts {total, approved}` + counts.
- `GET admin/v1/freelance-oversight/late` — `{total, groups, checked_at}`؛ المجموعات: `parts_late` (جزء عدّى مدته)، `deliveries_waiting` (تسليم مستني العميل +5 أيام)، `agreements_unanswered` (اتفاق انتهى وقته من غير رد)، `disputes_overdue` (نزاع عدّى `respond_by`)، `projects_waiting_review` (مشروع في المراجعة +يومين).
- الأمر `freelance:late-alerts` (يوميًا 08:00): لو فيه متأخر، بيبعت إشعار مرة واحدة في اليوم لكل من عنده `freelance-projects.view`. مفيش حاجة بتتغير.

### الدعم والنزاعات والدول والتقارير والمخاطر والتدقيق (FL-803 … FL-808)
كلها `admin/v1` وقراءة فقط إلا اللي مكتوب إنه بيغيّر. صلاحيات جديدة: `freelance-support` (view/update)، `freelance-risk` (view)، `freelance-audit` (view) — بتتضاف بـ `AdminPermissionSeeder`.
- **تصنيف النزاع (FL-803):** `POST freelance-disputes/{uuid}/classify {category}` (صلاحية `freelance-disputes.update`) — `payment · delivery · quality · conduct · scope · other`. القايمة والتفاصيل فيها `category` و`suggested_category` (بيتحسب من سبب صاحب النزاع: not_delivered/late ← delivery، no_response ← conduct، quality/not_as_agreed ← quality، out_of_scope ← scope، غير كده other). فلتر `?category=`. بيتسجل في سجل التدقيق.
- **تذاكر الدعم (FL-803):** `GET freelance-support/tickets[?status=&q=&project=uuid&dispute=uuid]` — تذاكر المحترفين + أي تذكرة مربوطة بمشروع/نزاع، بنفس شكل `AdminSupportTicketResource` + `link {kind, uuid}` و`pagination.counts` لكل حالة. `POST freelance-support/tickets/{number}/link {kind: project|dispute|none, uuid}` بيربط التذكرة (في `reference` = `freelance_project:{uuid}` / `freelance_dispute:{uuid}`) أو يشيل الربط. الرد والتعيين والإغلاق من شاشة الدعم الموجودة. مفيش تصنيف تلقائي.
- **الدول (FL-804):** `GET freelance-oversight/countries` — لكل دولة: `service_open` و`closed_by_availability` و`features` و`projects`. لو الأدمن قفل خدمة الفريلانس في الدولة من صفحة `service-availability` (country row بـ `service_enabled=false`) الخدمة بتتقفل في التطبيقات كمان (`FreelanceFeatures::allows/snapshot`). دولة محدش لمسها هناك بتفضل ماشية على مفاتيح الفريلانس (مفيش تغيير في اللي شغال).
- **التشغيل (FL-805):** `GET freelance-reports/operations[?country_id=&from=&to=]` — مشاريع/عروض/اتفاقات/أجزاء حسب الحالة، متوسط الساعات لحد أول عرض ولحد التعاقد، نزاعات حسب التصنيف. الفلوس (محجوز/محرَّر/عمولات/رسوم) في `freelance-reports/summary` زي ما هي.
- **سجل التدقيق (FL-806):** `GET freelance-audit[?action=&actor_id=&subject_id=&from=&to=]` — أفعال الفريلانس بس (`action` بيطابق البداية، مثلًا `freelance.dispute`)، الأحدث أولًا، مع اسم الموظف. اتضاف تسجيل لقرار النزاع (`freelance.dispute.decided`) وتصنيفه وقرار بلاغ الرسالة (`freelance.message_report.decided`) وقرار اعتراض التقييم (`freelance.review_objection.decided`) وربط التذكرة (`freelance.support.linked`). **المناوبات: لسه NEEDS-DECISION (اختيارية).**
- **المخاطر (FL-807):** `GET freelance-risk/overview` (عدد المحترفين والعملاء متوسطي/عاليي الخطورة، البلاغات المفتوحة حسب السبب، العقوبات السارية) و`GET freelance-risk/accounts[?side=freelancer|client&level=medium|high]` — الحسابات (محترفين وعملاء مع بعض) من قواعد FL-707 مع الأسباب وعدد العقوبات والبلاغات المفتوحة ضدهم. بيعرض بس؛ العقوبة من `freelance-sanctions` والبلاغ يتقرر في `freelance-message-reports`.
- **التحسين المستمر (FL-808):** `GET freelance-reports/improvement[?country_id=&from=&to=]` — أسباب الإلغاء حسب اللي ألغى (عميل/محترف)، أسباب عدم الرضا من استبيان الرضا لكل طرف، العروض المرفوضة/المسحوبة/المنتهية، الدعوات المرفوضة/المنتهية، ومشاريع انتهت من غير شغل. معلومات فقط.
- صلاحية `freelance-reports.view`. `freelance:reconcile` يوميًا 03:20 — لو فيه فروقات بيبلّغ الأدمنز اللي معاهم الصلاحية (مرة في اليوم بالكتير، من غير push).

## سطور السعر (FL-510)

- `client_cost.lines` في العروض، `breakdown.lines` في `offer-preview` للمحترف، و`money.lines` في الاتفاق: `[{key, label, amount_minor, total}]` — العميل: `price, client_fee, total`؛ المحترف: `price, commission, net`.
- فلوس الاتفاق دايمًا مجموع المراحل الحالية؛ للعميل `quoted_total_minor` = الإجمالي اللي اتقال قبل الاختيار (التقسيم ممكن يغيّره لو فيه حد أدنى للرسوم على كل مرحلة).

## المشاريع الخاصة والدعوات (FL-205)

- العميل (`mobile/v1/freelance/projects/{uuid}`، سويتش `invitations`): `GET invitations` (`summary{sent,accepted,declined,max,can_invite,can_make_public}` + القائمة)، `POST invitations {freelancers:[uuid]}` (حد أقصى سياسة `invitations.max_per_project`، الافتراضي 10)، `DELETE invitations/{invitation}`، `GET invitees?q=&all=1`، `POST make-public`.
- المحترف (`freelance/v1`): `GET invitations` (`page`/`per_page`/`status=sent|accepted|declined`)، `POST invitations/{uuid}/accept|decline` (يتطلب حساب موثّق، بيرجّع الدعوة اللي اتردّ عليها بس: `uuid` + `status` + `can`...، مش القائمة كلها)؛ `GET projects/{uuid}` فيه `invitation{uuid,status}`.
- المشروع الخاص يظهر للمدعوّين فقط؛ التقديم من غيرهم `freelance_project_invite_only`.
- أخطاء: `invitations_limit`, `invitation_not_open`, `invitation_closed`, `invitation_project_public`, `invitation_project_not_published`, `invitation_accepted_exists`.
- العميل يختار عرضًا واحدًا بالطريقة العادية؛ لو محدش قبل يقدر يحوّل المشروع لعام.

## نسخ المشروع والحد الأقصى للتكلفة والمعاينة ومشروعي (FL-209 / FL-210 / FL-211)

على `mobile/v1/freelance/projects` (سويتش `projects`):

| الطريقة | المسار | ملاحظات |
|---|---|---|
| POST | `{uuid}/duplicate` | (FL-209) مسودة جديدة بنفس العنوان والوصف والتخصص والمهارات النشطة والميزانية والحد الأقصى والمدة والظهور والأسئلة والملفات (نسخ مستقل). مفيش عروض ولا دعوات ولا اتفاق. أي مشروع يتنسخ مهما كانت حالته. بيحترم حد المشاريع المفتوحة (`freelance_project_limit`). الحدث `project.created` فيه `copied_from`. throttle `freelance-project-create` |
| POST | `{uuid}/hire-again` | (FS-102) «اتعاقد تاني» على مشروع اتنهى باتفاق مكتمل: مسودة **خاصة** نسخة من القديم + بيفتكر المحترف وخطة المراحل القديمة كمرجع بس (`hire_again{freelancer{uuid,name}, from_project, milestones[]}`، و`can.hire_again`). المحترف بيتدعى لوحده لما المشروع يتنشر. مفيش اتفاق قديم بيتنسخ؛ السعر والمدة والنطاق موافقة جديدة. `freelance_hire_again_unavailable` 422 لو مفيش اتفاق مكتمل أو المحترف مش موثّق. |
| GET | `{uuid}/preview` | (FL-210) `{project, issues[], can_submit}`. كل `issue` = `{severity: error\|warning, code, field, message}`. **أخطاء:** `missing_<field>`، أخطاء الإرسال (`project_budget_below_min` / `_above_max` / `_range`، مهارة معطّلة)، `cost_cap_below_budget` (الحد أقل من أقل ميزانية شامل الرسوم). **تحذيرات:** `budget_range_wide` (الأعلى > ×5 من الأقل)، `cost_cap_below_budget_top`، `duration_short_big_budget` (≤ 3 أيام وميزانية ≥ ×10 من أدنى ميزانية في البلد)، `duration_long_small_budget` (≥ 180 يوم وأعلى ميزانية ≤ ×2 من الأدنى). العتبات في `freelance.projects.preview`. `can_submit` = قابل للتعديل ومفيش أخطاء |
| PUT | `{uuid}/cost-cap` | `{max_cost_minor}` (≥ 1). قبل بدء الشغل بس (مسودة / مطلوب تعديل / مرفوض / قيد المراجعة / منشور / مرسى)، وإلا `freelance_project_invalid_state` 409. الحدث `project.cost_cap_set` |
| DELETE | `{uuid}/cost-cap` | شيل الحد (نفس الشروط) |

- `max_cost_minor` كمان بيتبعت في `POST /` و`PUT {uuid}` (اختياري). **الحد = أكتر مبلغ يدفعه العميل إجمالي شامل رسوم العميل.**
- التنفيذ: اختيار عرض إجماليه أعلى من الحد → `freelance_project_cost_cap_exceeded` 422 (`data.total_minor`, `max_cost_minor`)، وإرسال الاتفاق (`agreement/send`) بنفس الكود لو السعر + رسوم المراحل أعلى. العرض في قائمة العروض فيه `over_cap` (boolean). إشعار تخطّي الحد (358) مأجّل.
- المشروع (`present`) بقى فيه: `max_cost_minor`، `can.duplicate`، `can.set_cost_cap`، `progress{total,approved,in_review,price_minor,approved_minor}` (null قبل ما فيه اتفاق شغال/مكتمل)، `next_action` (`finish_draft` · `submit` · `fix_project` · `review_offers` · `send_agreement` · `review_delivery` · `fund_milestone` · `dispute` أو null لو الدور على الطرف التاني)، و`milestones[{uuid,position,title,amount_minor,duration_days,status,status_label}]` — **في `GET {uuid}` بس، مش في القائمة** (القائمة فيها `milestones: null`).

## إشعارات المشاريع المناسبة (FL-208)

مفيش مسار جديد: لما مشروع **عام** يتنشر (`POST admin/v1/freelance-projects/{uuid}/publish`) أو يتحوّل من خاص لعام (`make-public`)، بعد ما الرد يرجع بيتبعت حدث `freelance.project.matched` (`data.project` = uuid المشروع) لكل محترف يناسبه — في قايمة الإشعارات + لحظي (القناة الخاصة) + push بلغته (`type: freelance_project_matched`). الشغل في `Services/Projects/ProjectMatching.php` وبيتنفّذ بعد الرد (`NotificationCenter::later`) فمبيبطّئش الأدمن.

**قواعد المطابقة** (بسيطة، من غير تقييم):
- محترف **موثّق** (`verified`) من نفس دولة المشروع.
- تخصص المشروع هو أحد تخصصاته، أو تحت أحد تخصصاته (زي ما قايمة المشاريع بتعرضله).
- المهارات: لو المشروع والمحترف عندهم مهارات يبقى مهارة واحدة مشتركة على الأقل (محترف من غير مهارات بيدخل). كل ما المشترك أكتر اتبلّغ أول.
- مستقبل للعروض النهارده (مش موقوف ولا في إجازة).
- الميزانية: محترف سعره الاسترشادي `fixed` بيتبلّغ بس لو ميزانية المشروع بتتقاطع مع نطاقه (`rate_min`–`rate_max`، وطرف فاضي = مفتوح). `hourly`/غير محدد بيتخطّى الشرط.
- مرة واحدة لكل (مشروع، محترف) — جدول `freelance_project_alerts` (`score` = عدد المهارات المشتركة). مبيتبلّغش اللي اتدعى للمشروع (أي حالة) ولا اللي قدّم عليه عرض. المشروع الخاص ومش المنشور مبيتبلّغش عنهم.
- الحد الأقصى للمتبلّغين في المشروع الواحد: `freelance.matching.max_recipients` (100، الأنسب الأول).

الإشعار مفيهوش سويتش أو تفضيل للمحترف (بيقف لو أوقف استقبال الطلبات أو بقى في إجازة).

## المقابلة القصيرة (FL-310)

- داخل غرفة الشات، للطرفين: `GET chat/conversations/{uuid}/interviews`، `POST …/interviews {starts_at, duration_minutes: 10|15|20|30|45|60, note?}`، `POST …/interviews/{interview}/confirm|decline|cancel` (برفيكس العميل `mobile/v1/freelance/` والمحترف `freelance/v1/`).
- الحالات: `proposed, confirmed, declined, cancelled, superseded`. اللي اقترح مبيردش على اقتراحه (403 `interview_not_yours_to_answer`). الاقتراح الجديد بيلغي المفتوح.
- الوقت من 5 دقايق لـ 30 يوم قدام (`interview_time_invalid`). عند التأكيد: عنصر في تقويم العميل + رسالة نظام في الغرفة (`kind=system`)؛ تذكير قبل البداية بـ 15 دقيقة.
- المقابلة القصيرة جدولة وتذكير فقط، والكلام في الغرفة نفسها؛ مفيش مكالمة فيديو (قرار المالك).

## التسليم بملفات والمعاينة وقائمة القبول (FL-601 → FL-604)

- المحترف: `POST freelance/v1/projects/{uuid}/milestones/{milestone}/deliver` **multipart**: `note` (5–3000) و`files[]` (حتى 10، النوع والحجم من `freelance.upload_max_kb`). كل تسليم نسخة جديدة مرقّمة؛ الأخطاء: `delivery_too_many_files`.
- الطرفين (برفيكس كل تطبيق): `GET projects/{uuid}/milestones/{milestone}/deliveries` (الأحدث أولًا: `number, note, delivered_at, revision_reason, files[{id,name,mime,size,is_image,path}]`) و`GET …/deliveries/{delivery}/files/{media}` (الصورة inline وغيرها تحميل؛ `nosniff` و`no-store`؛ غير الطرفين 404).
- طلب التعديل (`…/revision {reason}`) بيكتب السبب على النسخة اللي العميل كان بيراجعها.
- العميل: `PUT …/milestones/{milestone}/checklist {items:[…]}` (حتى 10 بنود × 200 حرف) لحد ما الشغل يبدأ (`checklist_locked` 409)، و`POST …/checklist/{item}/check|uncheck` وقت المراجعة فقط (`checklist_not_reviewing` 409). التعليم ما بيشترطش للاعتماد، وبيتصفّر بعد جولة التعديل.
- **قوالب قائمة القبول (FS-105):** `GET mobile/v1/freelance/checklist-templates[?specialty=]` → `[{code, name, specialty_id, items[]}]` (بلغة المستخدم، حتى 10 بنود). العميل يختار قالب ويعدّل نسخته قبل `PUT …/checklist`. الأدمن: `admin/v1/freelance-checklist-templates` (`GET` · `GET options` · `POST` · `PUT {id}` · `PATCH {id}/status` · `DELETE {id}`) بصلاحية `freelance-checklist-templates`؛ اللي موجود من الأول: موقع، تطبيق موبايل، تصميم، تسويق، كتابة وترجمة.
- المرحلة في `agreement` فيها `deliveries_count` و`checklist[{uuid,text,checked}]` و`can.checklist_edit|checklist_tick`.

## فحص الملفات عند الرفع (FL-1205)

- قاعدة `Modules\Freelance\Rules\CleanUpload` (بتشغّل `Services\Security\UploadScanner`) على كل الرفع في الموديول: المعرض، الشهادات والهوية، مرفقات العرض، التسليم، أدلة النزاع، ملفات الشات، مرفقات المشروع، الصور الشخصية. بتقرأ **محتوى الملف الحقيقي** مش الاسم.
- بتترفض: امتداد ممنوع في أي مكان بالاسم (`report.pdf.exe`)، برنامج/سكريبت/صفحة تشغّل كود، اسم مش مطابق للمحتوى (صورة مزيفة)، PDF فيه كود نشط (JavaScript أو تشغيل)، ملف أوفيس فيه ماكرو، وملف zip فيه برنامج أو ماكرو أو مسار هروب أو قنبلة (عدد ملفات/حجم بعد الفك/نسبة ضغط) أو مش مقروء. أسباب الرفض في اللوج: `blocked_extension, blocked_type, program, script, disguised, pdf_active_content, macros, archive_unreadable, archive_bomb, archive_path, archive_program, malware, scanner_unavailable`.
- الرد عند الرفض: **422** برسالة `freelance.file_rejected` على حقل الملف؛ الملف ما بيتخزّنش، والمحاولة بتتسجّل في اللوج (مين/ايه/ليه/IP).
- **ده مش مضاد فيروسات.** لتفعيل ClamAV: `FREELANCE_CLAMAV_ENABLED=true` + `FREELANCE_CLAMAV_HOST` / `PORT` (3310) / `TIMEOUT` (10) — بيتبعت كل ملف للـdaemon (INSTREAM) وكلمة FOUND بترفضه. لو الـdaemon مش متاح الرفع بيترفض (`FREELANCE_CLAMAV_FAIL_CLOSED=true` افتراضيًا، رسالة `freelance.file_scan_unavailable`).
- أنواع ملفات التسليم: `jpg, jpeg, png, webp, gif, pdf, doc, docx, xls, xlsx, ppt, pptx, txt, zip` (الـrar اتشال لأنه مش بيتفحص).

## الخط الزمني للأدلة في النزاع (FL-606)

- الأدمن (صلاحية `freelance-disputes.view`، والقراءة بتتسجّل في الـaudit log):
  - `GET admin/v1/freelance-disputes/{uuid}/timeline` → `data.entries[{at, kind, actor, label, detail, files[{name,mime,size,path}]}]` الأقدم أولًا. `kind`: `agreement | approval | work | delivery | dispute | evidence | chat`؛ `actor`: `client | freelancer | admin | null`. الشات لحد 500 رسالة. بيسجّل `freelance.dispute.timeline_viewed`.
  - `GET admin/v1/freelance-disputes/{uuid}/timeline/export` → CSV (`text/csv`)، الخلايا اللي بتبدأ بـ `= + - @` بتتحوّل لنص. بيسجّل `freelance.dispute.timeline_exported`.
  - `GET admin/v1/freelance-disputes/{uuid}/deliveries/{delivery}/files/{media}` → ملف التسليم (الصورة inline، `nosniff`، `no-store`).
- اسم الملف في القوائم والتحميل هو الاسم اللي كتبه الشخص (مش الاسم المخزّن).


## ملاحظة شكل `can` في الأجزاء (2026-10-25)
في `GET …/agreement` كل جزء فيه `can` وهو **object دايمًا**: قايمة الإجراءات لما الاتفاق `active`، و`{}` لما مش نشط. التطبيقات بتقراه كـ map، فمينفعش يرجع `[]`.

- **إدارة المحترفين (FL-109):** `GET admin/v1/freelancers[?search=&status=&country_id=&specialty_id=&per_page=]` (`specialty_id` من تخصصات الملف؛ `pagination.counts` عدّاد كل حالة بعد باقي الفلاتر وقبل فلتر الحالة) و`GET …/options` (دول وتخصصات وحالات). `GET …/{id}` بيرجّع الملف (`profile`) وآخر طلب توثيق (`verification`) والمعرض (`portfolio`) وآخر 20 عرض (`offers`) و20 اتفاق (`projects`) و`stats` والمحافظ (`wallets`) و`audit` (آخر 30 تغيير على الحساب). `POST …/{id}/status {status: verified|profile_ready|suspended|blocked, reason}` — السبب إلزامي للإيقاف/الحظر ومسجّل في سجل التدقيق. الصلاحيات `freelancers.view` / `freelancers.update`.


## باقات المحترف واشتراكاته (FL-L01 — المرحلة 1) — 2026-10-29

**الفكرة:** ثلاث باقات: **Free** (الافتراضية، بتمشي على سياسة الدولة) و**Plus** و**Pro**. الباقة المدفوعة بتديك **عروض مجانية أكتر في الشهر** (أو بلا حد) و**عمولة أقل**، ولها **تجربة مجانية** أول مرة بس. السعر **شهري وسنوي (السنوي بخصم)** و**لكل دولة بعملتها**، والدفع **من محفظة المحترف** (`spend_only` الأول وبعدين القابل للسحب) والإيراد بيتسجّل في الدفتر تحت تصنيف `freelance_subscriptions`.

**القواعد:**
- الباقة **بتخفّض العمولة وعمرها ما بتزوّدها** (لو عمولة الدولة أقل من الباقة بتفضل عمولة الدولة)، والعروض المجانية = الأكبر بين رقم الدولة ورقم الباقة (Pro بلا حد: `quota.limit = null`).
- **العمولة بتتجمّد في الاتفاق** وقت ما يتعمل (`policy` snapshot)، فانتهاء الباقة بعد كده ما بيغيّرش اتفاق شغّال.
- **أول اشتراك** = تجربة مجانية (`trialing`) من غير خصم، وبتتجدد بسعر اليوم لو التجديد شغّال. بعد كده كل اشتراك بيتخصم فورًا. مفيش رصيد كفاية → 422 `freelance_subscription_insufficient` (فيه `data.shortfall_minor`) ومفيش حاجة بتتغير.
- **الترقية** (باقة أعلى أو سنوي بدل شهري): فورًا، والوقت اللي ما اتستخدمش من الفترة الحالية بيتخصم من السعر وتبدأ فترة جديدة. **التخفيض** (باقة أقل أو شهري بدل سنوي): بيستنى نهاية الفترة (`pending`)، واختيار الباقة الحالية تاني بيلغي المؤجّل. أثناء التجربة التغيير حر.
- **الإلغاء** = إيقاف التجديد، والباقة بتكمّل لآخر الفترة. **الاستئناف** قبل النهاية بيرجّع التجديد.
- **التجديد:** الأمر `freelance:renew-subscriptions` (كل ساعة) بيجدّد اللي خلصت فترته بسعر اليوم، ولو الرصيد ناقص الاشتراك بيبقى `past_due` وتفضل الباقة شغّالة **أيام السماح** (`freelance.plan_grace_days` = 3) بمحاولة كل يوم، وبعدها بينتهي (`expired`) ويرجع Free. بيفكّر المحترف قبل التجديد بـ `freelance.plan_remind_days` (3) أيام. في `past_due` التغيير مرفوض (409 `freelance_subscription_past_due`) والدفع بـ `subscription/renew`.
- مفتاح الميزة **`subscriptions`** (لكل دولة، من «مفاتيح الخدمة»): مقفول = لا بيع ولا الباقات بتشتغل والكل على Free، والـ API بيرجع 403 `freelance_feature_off`.

**المحترف — `/api/freelance/v1` (حارس `freelance_api`، تحت مفتاح `subscriptions`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `plans` | `{enabled, currency, decimals, plans[{slug,name,description,rank,free,current,prices{monthly,yearly},yearly_saving_minor,benefits{offers_unlimited,offers_monthly,commission_bp},trial_days}]}` — الباقات اللي ليها سعر في دولتي بس، `trial_days` = 0 لو استخدمت تجربتي. الأسعار minor بعدد خانات العملة (`decimals`). |
| GET | `subscription` | `{subscription, invoices[], quota}` — اشتراكي الشغّال وإلا آخر واحد (`null` لو عمري ما اشتركت). `subscription`: `uuid, plan{slug,name,rank}, period, status (trialing/active/past_due/expired), active, auto_renew, price_minor, currency, period_end, trial_ends_at, grace_until, canceled_at, pending{plan,period}`. |
| POST | `subscription` `{plan, period: monthly\|yearly}` | اشتراك أو تغيير (حسب إن كان فيه اشتراك شغّال). `free` أو باقة بلا سعر → 422 `freelance_plan_unavailable`. نفس الباقة والفترة → 409 `freelance_subscription_unchanged`. |
| POST | `subscription/cancel` · `subscription/resume` · `subscription/renew` | إيقاف التجديد · رجوعه · دفع تجديد فاشل دلوقتي. كلهم بيرجّعوا نفس جسم `GET subscription`. (throttle 10/دقيقة) |

`quota` (في `GET subscription` وفحص العرض) بقى فيه `plan` (slug) و`limit: null` معناها بلا حد.

**الأدمن — `/api/admin/v1` (صلاحية `freelance-plans`: `view` / `update`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `freelance-plans` | الباقات (+`subscribers`) و`revenue_30d[{currency,decimals,total_minor,invoices}]` و`subscribers` و`defaults`. |
| PUT | `freelance-plans/{plan}` | `name{ar,en}`, `description`, `offers_monthly`, `offers_unlimited`, `commission_bp` (0–10000), `trial_days` (0–90), `status`. باقة Free أرقامها ثابتة (بتتجاهل). |
| GET | `freelance-plans/{plan}/prices[?search=&missing=1&per_page=]` | كل الدول بسعرها الشهري والسنوي (فاضي حيث لم يُحدَّد) + `currency` و`decimals`. |
| PUT | `freelance-plans/{plan}/prices/{country}` `{monthly_minor, yearly_minor, status?}` | سعر الدولة. السنوي لازم ≤ 12 × الشهري (`freelance_plan_yearly_too_high`)، وFree بلا سعر (`freelance_plan_free_no_price`). مسجّل في الـaudit. |
| POST | `freelance-plans/fill-prices` | يضيف الباقات والأسعار الناقصة من الافتراضي (دولة جديدة) — **مش بيكتب فوق سعر موجود**. |
| GET | `freelance-plans/subscriptions[?status=&plan=&search=&per_page=]` | المشتركين، و`pagination.counts` عدّاد كل حالة. |

**الأسعار الافتراضية:** `php artisan db:seed --class=FreelancePlansSeeder` (داخل `DatabaseSeeder`) بيزرع الباقات و**سعر شهري وسنوي لكل دولة** من `config('freelance.plans')` (Plus 9$ و Pro 24$ شهريًا، خصم السنوي `plan_yearly_discount_bp` = 2000 أي 20%) بتحويل بأسعار صرف جدول العملات (ولو السعر placeholder 0.01 بيستخدم `plan_usd_rates`) وتقريب لسعر مقروء، وبعدد خانات كل عملة. تشغيله تاني آمن ومش بيغيّر سعرًا عدّله الأدمن.

**الإشعارات الجديدة (للمحترف):** `subscription.started` · `subscription.renewing` · `subscription.renewed` · `subscription.renewal_failed` · `subscription.expired` (الداتا فيها `type=freelance_subscription_*`، والتطبيق بيفتح شاشة «باقتي»).

**الجداول:** `freelance_plans` · `freelance_plan_prices` (فريد: باقة + دولة + فترة) · `freelance_subscriptions` · `freelance_subscription_invoices`. الاختبار: `tests/Feature/FreelancePlansTest.php`.


## شراء عروض إضافية (FL-L01 — المرحلة 2) — 2026-10-29

**الفكرة:** لما الحصة المجانية الشهرية تخلص، المحترف يشتري **حزمة عروض** من محفظته (`spend_only` الأول). الحزم 3 افتراضيًا (10 و25 و50 عرض) **بسعر لكل دولة بعملتها**، والإيراد بيتسجّل في الدفتر تحت تصنيف `freelance_offer_packs`.

**القواعد:**
- العروض المشتراة **بتتصرف بعد المجانية** (العرض بيتحفظ فيه `credit_id` والـ`free=false`)، **والدفعة اللي بتنتهي أول بتتصرف أول**. بتنتهي بعد `freelance.offer_credit_valid_days` (180، و0 = ما بتنتهيش).
- **المتبقي بيتحسب مش مخزّن** (الكمية − العروض المبعوتة عليها اللي لسه بتتحسب)، زي الحصة المجانية بالظبط: العرض اللي المحترف بيسحبه **قبل ما العميل يشوفه بيرجّع مكانه**، وبعد ما يتشاف مكانه بيروح.
- الحصة المجانية بتتجدد كل شهر، **والمشتراة ما بتتجددش ولا بتتأثر بتغيير الباقة**. الباقة اللي بلا حد (Pro) ما بتصرفش منها، ومحاولة الشراء بترجع 409 `freelance_offer_pack_not_needed`.
- بيتبعت `request_id` مع كل ضغطة: نفس الـ`request_id` تاني = نفس الشراء ومفيش خصم تاني. الإرسال بيقفل صف المحترف (`lockForUpdate`) فآخر عرض ما يتصرفش مرتين من مشروعين مع بعض.
- مفتاح الميزة **`offer_packs`** (لكل دولة) + لازم `free_offer_quota` شغّال (من غير حد شهري مفيش حاجة تتشترى). مقفول → 403 `freelance_feature_off` أو `freelance_offer_packs_closed`.
- فحص العرض (`offer-preview`) و`GET subscription` فيهم `quota` بقى فيه `credits` (المشترى المتبقي) و`packs` (ينفع يشتري ولا لأ). لما العرض يترفض بـ`offer_quota_exhausted` التطبيق يشوف `quota.packs` ويعرض «اشتري عروض إضافية».

**المحترف — `/api/freelance/v1` (حارس `freelance_api`، تحت مفتاح `offer_packs`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `offer-packs` | `{enabled, currency, decimals, unlimited, valid_days, credits{available,next_expires_at,batches[{uuid,quantity,remaining,expires_at}]}, packs[{slug,name,offers,price_minor}], purchases[], quota}` — الحزم اللي ليها سعر في دولتي وفعّالة بس. |
| POST | `offer-packs/{pack}/buy` `{request_id?}` | شراء (`pack` = الـslug). نفس جسم `GET`. الرصيد ناقص → 422 `freelance_offer_pack_insufficient` (`data.shortfall_minor`). حزمة غير موجودة أو بلا سعر → 422 `freelance_offer_pack_unavailable`. (throttle 10/دقيقة) |

**الأدمن — `/api/admin/v1` (نفس صلاحية `freelance-plans`: `view` / `update`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `freelance-plans/packs` | الحزم (+`sold_30d`) و`revenue_30d[{currency,decimals,total_minor,purchases}]` و`valid_days`. |
| PUT | `freelance-plans/packs/{pack}` | `name{ar,en}`, `offers` (≥1), `sort_order`, `status`. تغيير العدد ما بيمسّش مشتريات اتعملت (كل شراء بيحتفظ بكميته وسعره). |
| GET | `freelance-plans/packs/{pack}/prices[?search=&missing=1&per_page=]` | كل الدول بسعر الحزمة (فاضي حيث لم يُحدَّد). |
| PUT | `freelance-plans/packs/{pack}/prices/{country}` `{price_minor, status?}` | سعر الدولة (مسجّل في الـaudit). |
| GET | `freelance-plans/pack-purchases[?search=&pack=&per_page=]` | مين اشترى إيه، والمتبقي، وتاريخ الانتهاء. |
| POST | `freelance-plans/fill-prices` | بقى بيضيف كمان الحزم وأسعارها الناقصة (`packs`, `pack_prices` في الرد) — ما بيكتبش فوق سعر موجود. |

**الأسعار الافتراضية:** نفس `FreelancePlansSeeder` بيزرع الحزم (`config('freelance.offer_packs')`: small 10 عرض بـ 3$ · medium 25 بـ 6$ · large 50 بـ 12$) وسعر لكل دولة بنفس تحويل الباقات. الحزمة أغلى شوية للعرض الواحد من Plus (عشان الباقة تفضل الأحسن). تشغيله تاني آمن.

**الجداول:** `freelance_offer_packs` · `freelance_offer_pack_prices` (فريد: حزمة + دولة) · `freelance_offer_credits` (فريد: محترف + `request_id`) · عمود `freelance_offers.credit_id` (migration `2026_11_04_100000`). الاختبار: `tests/Feature/FreelanceOfferPacksTest.php`.


## الخدمات الجاهزة (FL-L04 — الدفعة أ: الباك إند والأدمن) — 2026-10-29

**الفكرة:** المحترف بيعرض **خدمة بسعر ثابت** بلحد 3 مستويات (`basic` إجباري، `standard`، `premium`) لكل مستوى سعر وأيام تسليم وعدد تعديلات ومميزات، ومعاها **إضافات مدفوعة** (سعر + أيام زيادة). العميل بيطلبها من غير عروض. الطلب **بيتحول لمشروع خاص** (`freelance_projects.service_id`) بعرض مقبول واتفاق **متبعت جاهز** (طلب العميل = موافقته)، فبيمشي في نفس الاتفاق والمراحل والتمويل والتحرير والنزاع والتقييم. **مفيش مسار فلوس جديد، ومفيش دفع قبل قبول المحترف.**

**دورة الخدمة** (الباب الوحيد `FreelancerServices`):
- `draft` / `rejected` → إرسال → `pending_review` → الموظف: `published` أو `rejected` (بسبب).
- لو المراجعة مقفولة في الدولة (`services.require_review = false`) الخدمة بتتنشر على طول.
- `published` ⇄ `paused`. تعديل خدمة منشورة أو متوقفة بيرجّعها للمراجعة لو المراجعة شغالة.
- الموظف يوقّف أي خدمة (`suspended` بسبب)، ويرجّعها → `paused` والمحترف يشغّلها.
- مسح الخدمة soft delete. مش مسموح والطلبات لسه مستنية أو شغالة (409 `freelance_service_has_orders`).

**القواعد:**
- محترف **موثّق** بس، ومش عليه تقييد شغل (`restricted_work`).
- أقصى عدد خدمات منشورة أو مستنية في نفس الوقت = `services.max_active` (الافتراضي 5). بعده 422 `freelance_service_limit`.
- كل مستوى أعلى **سعره أكبر** من اللي قبله، وكل الأسعار جوه `project.min_budget_minor` / `project.max_budget_minor` للدولة والتخصص.
- الحدود من `config('freelance.services')`: لحد 5 صور (لينك موقّع بيخلص بعد 30 دقيقة للعميل، زي معرض الأعمال)، 5 إضافات، 10 مميزات للمستوى.
- **الطلب:**
  - السعر = سعر المستوى + الإضافات، والأيام = أيام المستوى + أيام الإضافات.
  - عدد تعديلات المستوى بيبقى `review.free_revisions` في الاتفاق.
  - العمولة ورسم العميل زي أي مشروع في الدولة والتخصص (وعمولة باقة المحترف). العرض `free=false`، يعني **ما بيصرفش من حصة العروض**.
  - الطلب بيحتفظ بنسخة (`snapshot`) من العنوان والمستوى والمميزات والإضافات، فتعديل الخدمة بعد كده ما بيغيّرش الطلب.
  - العميل **ما يقدرش يعدّل** اتفاق الطلب ولا يقسّمه (409 `freelance_service_order_fixed`).
- **قبول المحترف** خلال `services.answer_hours` (الافتراضي 48). بعد القبول الاتفاق `active` والمشروع `in_progress`، والعميل يموّل المرحلة من محفظته.
  - رفض أو انتهاء المهلة (`Agreements::expireDue` كل ساعة) أو إلغاء العميل قبل القبول → الاتفاق بينتهي و**المشروع بيتلغي** (مش بيرجع يستقبل عروض).
- **حالة الطلب ما بتتخزنش**، بتتقرا من الاتفاق: `awaiting` · `active` · `completed` · `declined` · `expired` · `cancelled` (الفلتر `ended` = آخر 3).
- `request_id` = نفس الطلب لو الضغطة اتكررت. السؤال اللي المحترف حاطه (`requirements`) لازم العميل يجاوبه في `note`، ومفيش أرقام أو لينكات تواصل في الملاحظة.
- مفتاح الميزة **`services`** بيقفل إنشاء الخدمات وتصفحها والطلب الجديد. **الطلبات اللي اتعملت بتكمل** (`service-orders` تحت `projects` بس).

**المحترف — `/api/freelance/v1`:**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `services[?status=]` | خدماتي + `counts` لكل حالة + `limits` (الصور، الإضافات، المميزات، `max_active`) + `currency` و`decimals` (عملة دولتي) + `price{min_minor,max_minor}` (حدود السعر هنا) + `review` (المراجعة شغالة؟) + `answer_hours`، عشان الفورم يشتغل قبل أول خدمة. |
| POST | `services` | `{title, description, requirements?, specialty_id?, tiers[{level, name?, description?, price_minor, delivery_days, revisions?, features[]}], addons?[{title, price_minor, extra_days?}]}` → مسودة. |
| GET / PUT / DELETE | `services/{uuid}` | التفاصيل (`can{edit,submit,pause,resume,delete}` و`orders{awaiting,active,completed}`) / تعديل (اللي بيتبعت بس، و`tiers` و`addons` بيستبدلوا القايمة كلها) / مسح. |
| POST | `services/{uuid}/submit` · `pause` · `resume` | إرسال للمراجعة / إيقاف / تشغيل. |
| POST / GET / DELETE | `services/{uuid}/images` `{image}` · `services/{uuid}/images/{media}` | الصور (jpg/png/webp لحد 5MB، بتتفحص). |
| GET | `service-orders[?status=awaiting\|active\|completed\|ended]` · `service-orders/{uuid}` | الطلبات اللي جاتلي: `money{price_minor, commission_minor, net_minor}` و`answer_by` و`client.name` و`note` و`can{accept,decline}`. |
| POST | `service-orders/{uuid}/accept` · `decline {reason?}` | نفس موافقة أو رفض الاتفاق (محتاج حساب موثّق). |

**العميل — `/api/mobile/v1/freelance` (`user_api`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `services[?search=&specialty_id=&min_price=&max_price=&max_days=&sort=newest\|price_low\|price_high\|popular&freelancer=&per_page=]` | خدمات دولتي المنشورة لمحترفين موثّقين. الكارت: السعر اللي بيبدأ بيه (الأساسي) وأيامه، الغلاف، `completed_orders`، المحترف. |
| GET | `services/{uuid}` | الصفحة كاملة: المستويات والإضافات والصور (لينكات موقّعة) والسؤال، وأرقام المحترف (`rating`, `reviews_count`, `completed_projects`, `on_time_rate`). |
| GET | `services/{uuid}/quote?level=&addons[]=` | `{price_minor, client_fee_minor, total_minor, delivery_days, revisions, lines}` قبل التأكيد. |
| POST | `services/{uuid}/order` `{level, addons?, note?, request_id?}` | 201 = الطلب (`status: awaiting`). نفس `request_id` → 200 بنفس الطلب. الخدمة وقفت أو المحترف اتوقف → 409 `freelance_service_unavailable`. |
| GET | `service-orders[?status=]` · `service-orders/{uuid}` | طلباتي: `money{price_minor, client_fee_minor, total_minor}` و`project` (UUID المشروع، وبعد القبول كل حاجة من شاشات المشروع). |
| POST | `service-orders/{uuid}/cancel` | إلغاء قبل قبول المحترف بس. |
| GET | `service-images/{media}` | لينك الصورة الموقّع (من غير تسجيل دخول). |

اتفاق مشروع الطلب (`GET projects/{uuid}/agreement`) فيه `service_order` (UUID الطلب)، و`can.edit` / `can.split` بيبقوا `false`. قايمة عروض المحترف (`GET offers`) ما بتعرضش عروض الطلبات.

**الأدمن — `/api/admin/v1` (صلاحية جديدة `freelance-services`: `view` / `update`):**

| الطريقة | المسار | الوصف |
|---|---|---|
| GET | `freelance-services[?status=&country_id=&search=&per_page=]` | الطابور (المستنية الأقدم الأول) من غير المسودات، و`counts` لكل حالة. |
| GET | `freelance-services/{uuid}` · `freelance-services/{uuid}/images/{media}` | الخدمة زي ما العميل هيشوفها، والمحترف والطلبات. الصور من الـ endpoint ده بس. |
| POST | `freelance-services/{uuid}/approve` · `reject {reason}` · `suspend {reason}` · `unsuspend` | القرار (مسجّل في الـ audit، والمحترف بيوصله إشعار). |
| GET | `freelance-services/orders[?status=&search=&per_page=]` | كل الطلبات بالعميل والمحترف والفلوس كاملة. |

**الإعدادات (صفحة السياسات، لكل دولة وتخصص، مجموعة `services`):** `services.require_review` (true) · `services.max_active` (5) · `services.answer_hours` (48).

**الإشعارات:**
- للمحترف: `freelance.service.approved` · `service.rejected` · `service.suspended` · `order.received`.
- للعميل: الموجودة `agreement.active` / `agreement.declined` / `agreement.expired`.

**الجداول:** `freelance_services` (soft delete) · `freelance_service_tiers` (فريد: خدمة + مستوى) · `freelance_service_addons` · `freelance_service_orders` (فريد: عميل + `request_id`، `project_id` فريد) · عمود `freelance_projects.service_id` (migration `2026_11_05_100000`). الاختبار: `tests/Feature/FreelanceServicesTest.php`.

**FL-L04 خلص:** الباك إند والأدمن (أ)، وتطبيق المحترف (ب)، وتطبيق العميل (ج). الشاشات في [APP.md](APP.md). لينكات صور الخدمة موقّعة على المسار بس (`signed:relative`)، فالتطبيق بيركّبها على العنوان اللي بيوصله. صفحة الخدمة للعميل فيها كمان `answer_hours`.


## مزايا الباقات: الشارة وأولوية الظهور والإحصائيات (FL-L01 — المرحلة 4، الدفعة أ: الباك إند والأدمن) — 2026-10-29

**الفكرة:** الباقة المدفوعة بقت بتدّي — غير العروض والعمولة — **شارة** جنب اسم المحترف عند العملاء، و**أولوية** في ترتيب «الموصى بهم»، و**شاشة إحصائيات أدائي**، و**حد خدمات جاهزة أعلى**. الأدمن بيظبطها لكل باقة من شاشة الباقات. **مفيش جداول ولا مسار فلوس جديد** (4 أعمدة على `freelance_plans`، migration `2026_11_06_100000`).

| العمود | المعنى | Free | Plus | Pro |
|---|---|---|---|---|
| `badge` | شارة الباقة بتظهر | لا | نعم | نعم |
| `boost` | وزن في «الموصى بهم» (0–3، `freelance.plan_boost_max`) | 0 | 1 | 2 |
| `analytics` | 0 مقفولة · 1 أساسية (30 يوم) · 2 كاملة | 0 | 1 | 2 |
| `services_max` | أقصى خدمات جاهزة في نفس الوقت (`null` = سياسة الدولة) | — | 15 | 30 |

الافتراضي من `config('freelance.plans')` (الـmigration بيملّي الباقات الموجودة، والـseeder بيملّي الجديدة ومش بيلمس باقة موجودة). Free ثابتة على الصفر وأي قيمة ليها بتتجاهل.

**مين ياخد المزايا** (`FreelancePerks`): اشتراك **بيدّي باقته دلوقتي** (تجربة أو مدفوع، أو منتظر التجديد، أو `past_due` جوه أيام السماح) + مفتاح `subscriptions` شغّال في دولة المحترف + الباقة لسه معروضة (`status`). غير كده المحترف على Free ومعندوش حاجة. **عقوبة `work_limit` أو إيقاف بتشيل الـ boost** (الشارة بتفضل لأنها اللي دفع عليها). المزايا بتتقرا لقايمة كاملة بـ 2 استعلام (`warm`)، وبتتحفظ لنفس الطلب وبحد 10 ثواني بس.

**الشارة** `badge: {plan, name} | null` بتظهر في: كروت الخدمات وصفحتها (`freelancer.badge`) · قايمة عروض مشروعي والمقارنة وبروفايل المحترف (`freelancer.badge` و`badge`) · قايمة «ادعُ محترفين» (`invitees[].badge`).

**أولوية الظهور — ترتيب `recommended`:**
- بقى **الافتراضي** (من غير `sort`) في `GET mobile/v1/freelance/services` وفي `GET mobile/v1/freelance/projects/{uuid}/offers`، وفي قايمة `invitees` (الـboost الأول بعدين الاسم). جوه نفس الوزن: **الأحدث الأول**.
- أي ترتيب العميل يختاره (`newest` · `price_low` · `price_high` · `popular` · `cheapest` · `fastest` · `completeness`) **عادل ومش فيه boost**.
- الترتيب في قاعدة البيانات بـ subquery (`FreelancePerks::boostQuery`) فمفيش استعلام لكل محترف. لو الدولة قافلة `subscriptions` الترتيب العادي.

**حد الخدمات الجاهزة:** `FreelancePerks::servicesLimit` = الأكبر بين `services.max_active` للدولة و`services_max` للباقة (الباقة عمرها ما بتقلّله). بيطبّق في إرسال الخدمة للمراجعة، و`GET services` → `limits.max_active`، و`GET plans` → `benefits.services_max`. `benefits` في `GET plans` بقى فيه كمان `badge` و`boost` و`analytics`.

**المحترف — `GET /api/freelance/v1/insights[?days=30|90|365]`** (حارس `freelance_api`، تحت مفتاح `projects`؛ إحصائياتي أنا بس):

```
{ level, locked, subscriptions_enabled, allowed_days[], days, currency, decimals, upgrade:{slug,name}|null,
  data: null | {
    offers:     {sent, withdrawn, viewed, shortlisted, accepted, rejected, view_rate, accept_rate},
    services:   {received, awaiting, active, completed, declined, expired, cancelled, accept_rate},
    earnings:   {earned_minor, [bucket: week|month, series[{from, earned_minor}]]},        // series للكاملة بس
    reputation: {rating, reviews_count, completed_projects, completion_rate, on_time_rate, completed_in_period},
    [specialties: [{id, name, sent, accepted, accept_rate}]]                                // للكاملة بس
    views:      {profile, service, profile_before, service_before, by_source{offer,portfolio,market},   // FL-L11
                 [bucket, series[{from, profile, service}], top_services[{id,title,views,orders}]]}   // الأخيرين للكاملة بس
  } }
```
- **المقفولة (Free) ترجع 200 مش خطأ:** `locked: true`، `data: null`، و`upgrade` = أقل باقة عليها سعر في دولتي وتفتح أكتر (`null` لو الباقات مش بتتباع في الدولة، و`subscriptions_enabled: false`). التطبيق يعرض زر «ترقّى» ومنه لـ«باقتي».
- `days` المطلوب بيتقرّب لأكبر مدة مسموحة ≤ المطلوب (أو أصغر مسموحة): الأساسية `[30]`، الكاملة `[30, 90, 365]`. اللي رجع في `days` هو الفعلي.
- **القمع:** `sent` = العروض اللي بعتها في المدة (بدون عروض الطلبات الجاهزة لأنها اتعملت من الطلب)، `viewed` اللي العميل شافها، `view_rate`/`accept_rate` على العروض غير المسحوبة. الطلبات: `accept_rate` = اللي قبلها ÷ (قبلها + رفضها/انتهت من غير رد)، وإلغاء العميل مش رد.
- **الأرباح:** المحرَّر (`released`) في المدة. السلسلة أسبوعية لـ 30 يوم (5 أسابيع بتتعد لورا من النهارده) وشهرية (3 شهور لـ 90 و12 لـ 365) بالشهور الفاضية.
- محسوب على طول من الجداول الموجودة (من غير تخزين)، فالتطبيق يقراه عند الفتح ورجوع الاتصال بس (من غير polling).

**زيارات الملف (FL-L11):** جدول `freelance_profile_views` (محترف/خدمة/نوع `profile|service`/مصدر `offer|portfolio|market`/يوم/عدّاد `views`) — **من غير أي هوية للزائر**. بتتسجّل لما عميل مسجّل يفتح: ملف محترف من عرض (`offerFreelancer`)، أو من معرض الأعمال (`PortfolioViewController`)، أو خدمة من السوق (`ClientServiceController::show`). التكرار بيتقفل بـ `Cache::add` لمدة `freelance.profile_views.dedupe_minutes` (افتراضي 10 دقايق، `0` = كل فتحة بتتعد). الإجمالي بيتقارن بالفترة اللي قبلها بنفس الطول. الأرقام تحت `insights.data.views` (مقفولة مع باقة بدون إحصائيات).

**المحترف — `GET /api/freelance/v1/profile-coach`** (مفتاح `freelance`، **مجاني لكل الباقات**): `{percent, level: start|good|great, done, total, tips[{key, impact: high|medium|low, points, action: profile|skills|specialties|portfolio|verification|services}]}`. النصايح من خطوات الاكتمال الناقصة (بأوزان `FreelancerCompleteness::weights()`) + `more_skills` و`more_portfolio` و`verification` و`first_service`؛ مترتبة بالأثر ثم النقاط. قواعد ثابتة من غير AI.

**اختبارات المهارات والشارات (FL-L08)** — مفتاح الدولة `skill_tests` (الأدمن بيشغّله من «مفاتيح التشغيل»؛ مقفول = مفيش اختبار جديد والشارات اللي اتكسبت بتفضل ظاهرة).
- **المحترف** (حارس `freelance_api`): `GET skill-tests` → `{enabled, tests[{skill_id, name, questions, minutes, pass_percent, badge|null, in_progress, retake_at|null, can_start}], badges[{skill_id, name, score_percent, earned_at, expires_at}]}` (المهارات اللي في ملفي وليها اختبار شغّال وبنك ≥ 3 أسئلة بس) · `POST skill-tests/{skill}/start` → `{attempt_id, skill_id, expires_at, seconds_left, pass_percent, questions[{id, prompt, options[نصوص بس]}]}` (السيرفر بيسحب أسئلة عشوائية ويخلط الاختيارات ويجمّدهم في المحاولة؛ نفس الطلب تاني بيرجّع نفس المحاولة المفتوحة؛ **الإجابة الصح عمرها ما بتنزل**) · `POST skill-test-attempts/{attempt}/submit {answers: {question_id: موضع الاختيار المعروض}}` → `{status: passed|failed|expired, passed, score_percent, correct_count, total, pass_percent, badge|null, retake_at|null}` (التصحيح في السيرفر؛ سؤال متسايب = غلط؛ التسليم مرتين بيرجّع النتيجة الأولى؛ متأخر أكتر من 30 ثانية عن الميعاد = `expired`).
- **الأخطاء** (`error_code`): `freelance_skill_test_closed` (403) · `_off` (404) · `_not_yours` (422، المهارة مش في ملفي) · `_held` (422، عندي شارة سارية) · `_wait` (422، استنى `retake_hours` بعد رسوب/انتهاء وقت) · `_few` (422) · `_gone` (404).
- **الشارة:** نجاح (`score ≥ pass_percent`) = شارة المهارة (`freelancer_skill_badges`، فريدة لكل محترف+مهارة) بتنتهي بعد `freelance.skill_tests.badge_valid_days` (365؛ 0 = من غير نهاية). بتظهر للعميل **بس** وهي سارية (مش ملغية ولا منتهية) **والمهارة لسه في ملف المحترف** — لو شالها الشارة بتختفي. الإعدادات الافتراضية للاختبار في `freelance.skill_tests.defaults` (10 أسئلة · 15 دقيقة · 70% · انتظار 24 ساعة).
- **عند العميل:** `skill_badges[{skill_id, name, score_percent, earned_at, expires_at}]` (أفضل 6 بالدرجة) جوه `freelancer` في عروض المشروع وبروفايل المحترف، وفي كارت الخدمة وقوايم الدعوات. وبتكسر التعادل في ترتيب «الموصى بهم» **بعد** وزن الباقة (`FreelancePerks::orderByBoost` + `ClientOfferViews::recommended`).
- **الأدمن** (صلاحيات `freelance-catalogs.*`): `GET freelance-skill-tests[?search&only=with_test|on]` (المهارات بعدد الأسئلة والشارات) · `GET freelance-skill-tests/{skill}` (الإعدادات + كل الأسئلة **بالإجابة الصح**) · `PUT freelance-skill-tests/{skill} {status?, questions_count(3–50)?, minutes?, pass_percent?, retake_hours?}` (التشغيل محتاج ≥ 3 أسئلة شغّالة) · `POST freelance-skill-tests/{skill}/questions` · `PUT|DELETE freelance-skill-questions/{id}` (سؤال = `prompt{ar,en}` + 2–6 اختيارات `{text{ar,en}, correct}` بالظبط واحد صح؛ مسح/تعطيل لحد ما يبقى أقل من 3 بيقفل الاختبار لوحده) · `GET freelance-skill-badges[?skill_id&state=valid|revoked|all]` · `POST freelance-skill-badges/{id}/revoke {reason?}` (بيسمح للمحترف يعيد الاختبار فورًا).

**الاستشارات المدفوعة (FL-L07)** — الاستشارة **عرض زي الخدمة الجاهزة** (مش جزء من صفحة الخدمة): المحترف يعمل **أكتر من استشارة**، كل واحدة بعنوان ووصف ومدد (15 · 30 · 45 · 60 · 90 دقيقة، لحد 4) بسعر لكل مدة ومواعيد أسبوعية بتوقيته، وبتعدي **مراجعة الأدمن** قبل ما العملاء يشوفوها (`draft` ← `pending_review` ← `published` | `rejected`، `published` ⇄ `paused`، و`suspended` من الأدمن؛ بدون مراجعة في الدولة (`consultations.require_review = false`) بتتنشر على طول؛ التعديل على منشورة بيرجعها للمراجعة؛ أقصى عدد `consultations.max_active` = 5 منشورة أو مستنية). العملاء بيشوفوها في **قايمة لوحدها جنب الخدمات الجاهزة**. العميل بيطلب ميعاد فاضي والفلوس بتتحجز من محفظته على طول، **والمحترف لازم يوافق** (والميعاد بيتقفل على كل استشاراته لما يوافق)، **والجلسة مكالمة فيديو (FL-L16) — مفيش شات للاستشارة**. ميزة الدولة `consultations` (لو مقفولة: مفيش حجز جديد، والحجوزات بتكمل). الجداول: `freelance_consultation_offers` (كذا صف للمحترف: `uuid, country_id, title, about, status, status_reason, submitted_at, reviewed_at, reviewed_by, published_at, options[{minutes, price_minor}], weekly[{day 0=الأحد…6, from, to}], timezone`) و`freelance_consultations` (الحجز بفلوسه وحالته، و`offer_id` للعرض اللي اتحجز منه) و`freelance_earnings.consultation_id` و`freelancers.timezone`.
- **المواعيد الفاضية:** بداية على نص الساعة (ربع الساعة للمدد 15/45) والمدة كلها جوه فترة من مواعيد الأسبوع، بعد `consultations.min_notice_hours` (2) ولحد `consultations.max_days_ahead` (30) يوم، مش في إجازة (FL-106)، ومش متداخلة مع حجز تاني. بترجع لحظات UTC والتطبيق بيعرضها بساعته.
- **الطلب:** `POST consultations` بيعمل طلب `pending`: بيتأكد إن الميعاد فاضي (`freelance_consultation_slot_taken` 409) وبيحجز السعر + رسوم العميل من المحفظة (`freelance_wallet_insufficient` 422 بالناقص، ومفيش طلب). **الطلب لوحده مش بيقفل الميعاد** — أكتر من عميل يقدروا يطلبوا نفس الميعاد. المحترف بيوصله `consultation.requested` وعنده `consultations.response_hours` (24) أو لحد الميعاد (الأقرب) يرد (`respond_by` في الرد).
- **رد المحترف:** `POST consultations/{uuid}/accept` ← الحالة `booked`، تحت نفس القفل: الميعاد بقى بتاعه، **وأي طلب `pending` تاني بيتداخل مع الساعات دي بيتلغي (`declined`، `cancelled_by = system`، `cancel_reason = taken`) وفلوسه بترجع** والعميل يوصله `consultation.declined`، ومن بعدها محدش يقدر يحجز الميعاد (409). بيتضاف لتقويم العميل والعميل يوصله `consultation.accepted`. `POST consultations/{uuid}/decline {reason?}` ← `declined` والفلوس ترجع كاملة. لو ما ردّش (أو جه الميعاد) الأمر `freelance:sweep-consultations` بيلغي الطلب (`cancel_reason = expired`) ويرجّع الفلوس، والمحترف يوصله `consultation.expired`. العميل يقدر يسحب الطلب `pending` في أي وقت (إلغاء مجاني). الحالة: `pending` ← `booked` | `declined` | `cancelled`؛ `booked` ← `completed` | `cancelled` | `disputed` ← `completed` | `refunded`.
- **الإلغاء (قبل البداية بس):** المحترف → العميل بيسترد كله. العميل قبلها بـ`consultations.free_cancel_hours` (24) أو أكتر → بيسترد كله؛ بعد كده → الفلوس للمحترف (`late_cancel`).
- **بعد الجلسة:** العميل عنده `consultations.complaint_hours` (24): «كانت كويسة» (`confirm`) → الفلوس تنزل للمحترف دلوقتي؛ «بلّغ عن مشكلة» (`complain`) → `disputed` والأدمن يقرر (`pay` أو `refund`). لو ما قالش حاجة الأمر `freelance:sweep-consultations` (كل 5 دقايق) بيدفع للمحترف، وبيبعت كمان تذكير قبل البداية بـ30 دقيقة للطرفين وسؤال «كانت عاملة إيه؟» للعميل عند النهاية.
- **التقييم:** بعد ما الاستشارة تبقى `completed` (العميل قال «كانت كويسة»، أو الوقت دفع، أو الأدمن قرر `pay`) العميل يقيّمها **مرة واحدة** خلال `ratings.window_days` (14) يوم من الدفع: `POST consultations/{uuid}/review {stars: 1-5, comment?}` (قبل الدفع أو بعد المهلة `freelance_consultation_review_closed` 409، والمرة التانية `freelance_consultation_review_exists` 409). التقييم **بيظهر على طول** (مفيش تقييم راجع من المحترف) والمحترف يوصله `consultation.rated`. بيتحفظ في `ratings` (rateable = الاستشارة) و`freelance_reviews` مع تقييمات المشاريع (`consultation_id`؛ `agreement_id`/`project_id` بقوا nullable)، فبيدخل في متوسط المحترف وعدده ومؤشر الجودة و«تقييمي» وملفه. كل رد استشارة فيه `review {can_rate, rate_until, rating{stars, comment, removed, at}}` (للمحترف `can_rate` دايماً false). الاعتراض (FL-706) لتقييمات المشاريع بس.
- **الدفع للمحترف:** الحجوزات بتتسحب، والصافي (السعر − العمولة) بيدخل أرباحه بأيام الانتظار المتجمّدة على الحجز (`earnings.hold_days`)، ورسوم العميل والعمولة بيتسجلوا دخل. كل حركة ليها مفتاح (`freelance:consultation:{uuid}:…`). القواعد (الساعات وأيام الانتظار) بتتجمّد على الحجز.
- **المحترف** (`/api/freelance/v1/consultations`): `GET info` (المدد المتاحة `lengths`، `max_options`، `max_active`، `review`، `rules`، `stats` فيها `pending`، `suggested_weekly`) · `GET offers` · `POST offers {title, about?, options[{minutes, price_minor}], weekly[{day, from, to}], timezone}` (مسودة؛ الفترات المتداخلة `freelance_consultation_hours_overlap`، أرقام تواصل في النص ممنوعة) · `GET|PUT offers/{uuid}` · `DELETE offers/{uuid}` (مش مسموح لو فيه طلب أو جلسة جاية: `freelance_consultation_has_bookings` 409 — يوقفها بدل المسح) · `POST offers/{uuid}/submit|pause|resume` (الإرسال محتاج عنوان ومدة ومواعيد وإلا `freelance_consultation_incomplete`؛ الحد الأقصى `freelance_consultation_listing_limit`؛ حالة غلط `freelance_consultation_listing_state`). كل عرض في الرد: `status, status_reason, options[+commission_minor/net_minor], weekly, timezone, live, bookings{pending,upcoming,completed}, can{edit,submit,pause,resume,delete}`. الحجوزات: `GET consultations[?tab=upcoming|past|cancelled]` (الطلبات `pending` في «الجاية» و`declined` في «الملغية») (+ `counts`) · `GET consultations/{uuid}` · `POST consultations/{uuid}/accept|decline|cancel`.
- **العميل** (`/api/mobile/v1/freelance`): `GET consultation-offers[?search=&per_page=]` (الكروت: `uuid, title, about, minutes[], from_minor (الإجمالي), next_slot, freelancer{…}`) · `GET consultation-offers/{uuid}` (الإجمالي لكل مدة + `next_slot` + `rules`) · `GET consultation-offers/{uuid}/slots?minutes=` (لحظات UTC بتتعرض بساعة الجهاز؛ الميعاد المقبول من أي استشارة تانية لنفس المحترف مش بيظهر) · `POST consultations {offer, minutes, starts_at, topic (10+ حروف), request_id}` · `GET consultations[?tab=]` · `GET consultations/{uuid}` · `POST consultations/{uuid}/cancel|confirm|complain {text}` · `POST consultations/{uuid}/review {stars, comment?}`. صفحة الاستشارة قبل الحجز فيها `freelancer.reviews` (آخر 5: `kind` = project | consultation، `project` = العنوان). ملف المحترف من العرض فيه `consultations = [{uuid, title, from_minor, currency, minutes}]` (فاضية لو مفيش)، وصفحة الخدمة مفيهاش استشارة.
- **الأدمن:** مراجعة العروض: `GET freelance-consultation-offers[?status=&search=]` (اللي مستنية مراجعة الأول، `counts`) · `GET freelance-consultation-offers/{uuid}` · `POST …/{uuid}/approve|unsuspend` · `POST …/{uuid}/reject|suspend {reason}`؛ والحجوزات: `GET freelance-consultations[?status=&search=]` (الشكاوى الأول، `counts`) · `GET freelance-consultations/{uuid}` · `POST freelance-consultations/{uuid}/resolve {decision: pay|refund, note?}` — صلاحيات `freelance-consultations.view|update`. الإعدادات في السياسات (مجموعة `consultations`، منها `require_review` و`max_active`).
- **الإشعارات:** المحترف `consultation.{listing_approved, listing_rejected, listing_suspended, requested, expired, cancelled, reminder, complained, paid, refunded, rated}` (الـdata فيها `consultation_offer` للعرض)، العميل `consultation.{accepted, declined, cancelled, reminder, review_due, refunded, complaint_closed}`، والأدمن «شكوى على استشارة». الـdata فيها `consultation`. **الوقت في النص بيتكتب بتوقيت جهاز المستلم**: التطبيقين بيبعتوا `X-Timezone` وبيتحفظ على `users.timezone` و`freelancers.timezone` (middleware `RememberTimezone`).

**الاشتراكات الشهرية في الخدمات (FL-L05)** — الخدمة ممكن تتباع مرة واحدة أو شهري (`billing` = `once` | `monthly` في `POST|PUT services`؛ الشهرية لازم كل مستوى + أطول إضافة ≤ `freelance.subscriptions.period_days` (30) وإلا `freelance_service_monthly_days` 422). الكارت وصفحة الخدمة فيهم `billing`، والصفحة فيها `subscription{period_days, renew_window_days, grace_days, notice_days}` (null لو مش شهرية).
- **البداية:** طلب خدمة شهرية = أول شهر (طلب عادي) + `freelance_service_subscriptions` بحالة `pending` بالشروط متجمّدة (المستوى، المميزات، الإضافات، السعر). الطلب فيه `subscription{uuid, cycle}`. قبول المحترف لأول شهر → `active` و`period_ends_at` = البداية + 30 يوم؛ الرفض/عدم الرد/إلغاء العميل → `ended` (`first_declined|first_expired|first_cancelled`).
- **العميل بيأكّد كل شهر (مفيش تجديد تلقائي):** `POST service-subscriptions/{uuid}/renew` متاح من `renew_window_days` (3) قبل نهاية الشهر لحد `grace_days` (2) بعدها (غير كده `freelance_subscription_renew_closed` 409). بيعمل طلب الشهر الجاي **بالسعر المتجمّد**، ويبدأه على طول (المحترف موافق على الاشتراك)، ويحجز فلوسه من المحفظة — كله أو ولا حاجة (`freelance_wallet_insufficient` 422 بالناقص). الشهور ماشية على نفس التقويم (`period_ends_at += 30`). المحترف بيوصله `retainer.renewed`.
- **الإيقاف بإشعار:** `POST service-subscriptions/{uuid}/cancel` (العميل أو المحترف): لو اتطلب قبل نهاية الشهر بـ`cancel_notice_days` (7) أو أكتر → بيخلص مع نهاية الشهر ده (ومفيش تأكيد للجاي)؛ غير كده → مع نهاية الشهر اللي بعده (والعميل بيأكّده). `POST .../keep` = اللي طلب الإيقاف يرجع فيه. الطرف التاني بيوصله `retainer.cancel_requested` / `retainer.kept`.
- **الأمر `freelance:sweep-subscriptions` (كل ساعة):** تذكير واحد للعميل لما التأكيد يفتح (`client.retainer.renew_due`)، وإنهاء اللي وصل ميعاد إيقافه (`cancelled_client|cancelled_freelancer`) أو اللي الشهر خلص + أيام السماح من غير تأكيد (`not_renewed`)، والطرفين بيوصلهم `retainer.ended`، ومكان الطاقة بيتفضّى.
- **الطاقة (FL-L10):** الاشتراك الـ`active` = مشروع شغّال واحد طول مدته (حتى بين الشهور)؛ اتفاقات شهوره مش بتتحسب تاني، وبداية الشهر الجاي مش بتتمنع بالحد.
- **الردود:** `GET service-subscriptions[?status=pending|active|ended]` و`GET service-subscriptions/{uuid}` للعميل (`/api/mobile/v1/freelance`) وللمحترف (`/api/freelance/v1`): `{uuid, status, end_reason, service, level, tier_name, features, addons, price_minor, currency, decimals, delivery_days, revisions, money (كل طرف من ناحيته), cycle, started_at, period_ends_at, renew{opens_at, due_by, open}|null, cancel{by, requested_at, ends_at}|null, notice_days, current{uuid, cycle, status, project}, client|freelancer, can}`. الأدمن: `GET freelance-services/subscriptions[?status=&search=]` (صلاحية `freelance-services.view`).

**الطاقة الاستيعابية وقايمة الانتظار (FL-L10)** — المحترف بيحدد أقصى عدد مشاريع **شغّالة** في نفس الوقت (`freelancers.capacity_max`؛ فاضي = من غير حد). **اللي بيتحسب: الاتفاقات `active` بس** (طلب الخدمة الجاهزة اتفاق برضه)؛ العروض والطلبات اللي مستنية رد مش بتتحسب. المكان المحجوز لأول واحد في الدور بيتحسب مشغول للباقيين.
- **المحترف:** `GET capacity` → `{max|null, max_choice, running, holds, waiting, full, line[{id, status: waiting|offered, position, hold_until, joined_at, service{uuid,title}, client{name}}]}` · `PUT capacity {max?}` (من غير `max` أو `null` = من غير حد؛ أكبر من `freelance.capacity.max_choice` (20) → `freelance_capacity_too_high` 422). رفع الحد بيدّي الأماكن الفاضية للي في الدور على طول.
- **بدء الشغل:** موافقة المحترف على أي اتفاق (`Agreements::approve`) بتتأكد من الحد **تحت قفل على صف المحترف**، فموافقتين في نفس اللحظة عمرهم ما يعدّوا الحد → `freelance_capacity_full` 409 (`:max`).
- **العميل:** كارت الخدمة فيه `at_capacity`، وصفحة الخدمة فيها `capacity{full, waiting, mine|null{status, position, hold_until}}`. الطلب والمحترف مليان (ومش محجوزلي مكان) → `freelance_at_capacity` 409 (`data.waitlist = true`). `POST services/{uuid}/waitlist` (لازم يكون مليان، وإلا `freelance_waitlist_not_full`؛ مرة واحدة لكل محترف `freelance_waitlist_already`) · `DELETE services/{uuid}/waitlist` (`freelance_waitlist_none` 404). الاتنين بيرجّعوا `capacity` الجديدة. الطلب بعد ما المكان اتحجزلي بيستهلك الحجز (`ordered`).
- **الدور:** لما مكان يفضى (اتفاق بطّل `active` — observer على `FreelanceAgreement` بعد الـcommit — أو الحد زاد، أو حد ساب القايمة وهو حاجز، أو حجز خلص) أقدم واحد مستني بيبقى `offered` والمكان محجوزله `freelance.capacity.hold_hours` (24 ساعة) وبيوصله إشعار `client.waitlist.turn`. الحجز اللي محدش استخدمه بيخلص بأمر `freelance:sweep-waitlist` (كل 5 دقايق) والمكان بيروح للي بعده. المحترف بيوصله `freelancer.waitlist.joined` لما حد يدخل الدور.

**الأدمن:** `PUT freelance-plans/{plan}` بقى بياخد `badge` (bool) · `boost` (0–3) · `analytics` (0–2) · `services_max` (1–1000 أو `null`)، والرد وقايمة الباقات فيهم نفس الحقول. مسجّل في الـaudit. صلاحية `freelance-plans.update`.

**اختبار:** `tests/Feature/FreelancePerksTest.php` (12 اختبار: ضبط الأدمن، متى تظهر المزايا وتختفي، عقوبة العمل، ترتيب الخدمات والعروض والدعوات والشارة، حد الخدمات، الإحصائيات المقفولة والأساسية والكاملة وأرقامها).

## العروض الخاصة للعملاء السابقين (FL-L09 — الدفعة أ) — 2026-11-02

**الفكرة:** المحترف يبعت **عرض خاص** لعميل **خلّص معاه شغل قبل كده** (اتفاق `completed` أو استشارة `completed`) — والعرض هو موافقة المحترف، **فقبول العميل بيبدأ الشغل على طول**. ميزة الدولة `special_offers` (مقفولة لو مفيش قاعدة؛ لو اتقفلت ما يتبعتش عروض جديدة، والعميل يقدر يرد على اللي وصلته لحد ما تنتهي). الجداول: `freelance_special_offers` و`freelance_special_offer_mutes`. أي خصم **على حساب المحترف** (هو اللي بيحط السعر)؛ العمولة ورسوم العميل بيتحسبوا على السعر المعروض زي أي مشروع.

**الأنواع (`kind`):**
- `custom` — شغل جديد: `title` (5+)، `description` (30+)، `price_minor` (≥ `project.min_budget_minor` لو متحدد)، `delivery_days` (1–365)، `revisions` (0–20)، `specialty_id?` (من تخصصاته، وإلا الأساسي). القبول = مشروع **خاص** (`visibility = private`) + عرض المحترف مقبول + اتفاق **`active`** على طول (العميل بعته = موافقته، والمحترف = عرضه)، والتعديلات المجانية = `revisions`، والعميل بعدها يموّل المرحلة زي أي مشروع. الحدث `project.special_offer`.
- `service` — مستوى من خدمة منشورة (مش شهرية) بسعر **أقل** من سعره العادي (`price_not_lower` لو مش أقل): `service`, `level`, `price_minor`. القبول = نفس طلب FL-L04 (`freelance_service_orders`) بالسعر الخاص **ومقبول من المحترف على طول** (اتفاق `active`). الأيام والتعديلات = المستوى.
- `consultation` — استشارة منشورة بخصم على كل المدد: `consultation`, `discount_bp` (500–9000 = 5%–90%). القبول بياخد `minutes` + `starts_at` + `topic` (10+) ويحجز بالسعر بعد الخصم، **والحجز بيتأكد على طول (`booked`)** من غير ما المحترف يوافق — والطلبات `pending` التانية على نفس الساعات بتترفض وفلوسها ترجع (نفس منطق قبول FL-L07). مفيش إشعار `consultation.requested` للمحترف (بيوصله `special_offer.accepted`).

**الحدود (سياسة، مجموعة `special_offers`):** `special_offers.daily_limit` (10 في اليوم للمحترف → `freelance_special_offer_daily_limit` 429) · `special_offers.open_per_client` (1 مفتوح لنفس العميل → `freelance_special_offer_client_has_open` 409) · `special_offers.cooldown_days` (7 بعد رفض العميل → `freelance_special_offer_cooldown` 409 ومعاه `until`) · `special_offers.max_valid_days` (14؛ `valid_days` من 1 لده، الافتراضي 7 → `freelance_special_offer_valid_days` 422). ممنوع أي وسيلة تواصل في العنوان والوصف والرسالة (`freelance_offer_contact_blocked`). `request_id` بيخلّي الضغطة المكررة عرض واحد.

**المحترف** (`/api/freelance/v1/special-offers`، middleware `freelance.feature:special_offers`):
- `GET clients[?search=]` → عملاؤه السابقين (الأحدث أولاً): `{key (32 حرف، ثابت ومش رقم العميل), name, avatar_url, works, projects, consultations, last_title, last_at, open_offer, can_send, blocked_reason: has_open|cooldown|null, blocked_until}`. العميل اللي وقّف عروضه **ما بيظهرش**. مفيش موبايل ولا إيميل.
- `GET compose` → `{currency, decimals, kinds{custom, service, consultation}, services[{uuid, title, levels[{level, label, name, price_minor, delivery_days, revisions, features}]}], consultations[{uuid, title, lengths[{minutes, price_minor}]}], specialties, rules{daily_limit, open_per_client, cooldown_days, max_valid_days, default_valid_days, min_discount_bp, max_discount_bp}, min_price_minor, today_left, stats{sent, seen, accepted, accept_rate}, counts{open, accepted, past}}`.
- `GET quote?price_minor=&specialty_id=` → `{price_minor, commission_minor, net_minor, client_fee_minor, client_total_minor, currency, lines}`.
- `POST /` `{client, kind, title?, description?, message?, specialty_id?, service?, level?, consultation?, price_minor?, discount_bp?, delivery_days?, revisions?, valid_days?, request_id?}` (محتاج `freelance.verified`) → 201 · العميل بيوصله `special_offer.received`.
- `GET /[?tab=open|accepted|past]` (+ `counts` و`stats` في الرد) · `GET {uuid}` (فيه `viewed_at`) · `POST {uuid}/withdraw` (لو لسه مستني).

**العميل** (`/api/mobile/v1/freelance/special-offers`، من غير مفتاح ميزة عشان يقدر يرد على اللي وصله):
- `GET /[?tab=]` (+ `counts`) · `GET waiting` → `{count}` (الشارة في الصفحة الرئيسية للعمل الحر) · `GET {uuid}` (أول فتح بيسجّل `viewed_at` للمحترف).
- `POST {uuid}/accept {request_id?, minutes?, starts_at?, topic?}` (الـ3 الأخيرة إجبارية للاستشارة) → `result{project, service_order, consultation}` · المحترف بيوصله `special_offer.accepted`. الضغطة التانية على عرض اتقبل بترجّع نفس النتيجة.
- `POST {uuid}/decline {reason? (≤300), mute?}` → المحترف بيوصله `special_offer.declined` (والسبب بيظهرله). `mute = true` بيوقف عروضه.
- `PUT freelancers/{uuid}/mute {on}` (لمحترف بعتله عرض قبل كده بس) — الإيقاف بيقفل كمان العروض المستنية.
- الرد (للطرفين، كل واحد من ناحيته): `{uuid, kind, kind_label, status (العرض اللي عدّى وقته بيبان expired حتى قبل الـsweep), status_label, title, message, description, specialty, service{uuid,title,level,level_label,tier_name,features}|null, consultation{uuid,title,lengths[{minutes, list_price_minor, price_minor, client_fee_minor, total_minor}]}|null, price_minor, list_price_minor, saving_minor, discount_bp, delivery_days, revisions, currency, decimals, money (العميل: price/client_fee/total + lines؛ المحترف: price/commission/net + lines), expires_at, sent_at, viewed_at (للمحترف), answered_at, decline_reason, freelancer|client, result, muted (للعميل), can{accept, decline}|{withdraw}}`. الاتفاق فيه `special_offer` (uuid العرض اللي جه منه).

**الساعة:** الأمر `freelance:sweep-special-offers` (كل ساعة) بيعلّم المنتهي `expired`، وبيبعت للعميل **مرة واحدة** `special_offer.ending` لما يفضل أقل من يوم (لعرض عمره يوم على الأقل).

**الأدمن:** `GET freelance-special-offers[?status=&kind=&search=]` (+ `counts` لكل حالة) · `GET freelance-special-offers/{uuid}` — قراءة بس، صلاحية `freelance-projects.view`. الحدود في «الأسعار والقواعد» (مجموعة «العروض الخاصة») والمفتاح في «مفاتيح الخصائص».

**الإشعارات:** المحترف `special_offer.accepted|declined` (`{name, title}`)، العميل `special_offer.received|ending` (`{name, title}`). الـdata فيها `special_offer` (والـ`project`/`consultation` بعد القبول).

**اختبار:** `tests/Feature/FreelanceSpecialOffersTest.php` (8 اختبارات: العملاء السابقين بس، الشغل المخصوص بيبدأ اتفاق `active`، الخدمة بسعر أقل بتبقى طلب مقبول، الاستشارة بخصم بتتأكد على طول، عرض مفتوح واحد ومهلة بعد الرفض وإيقاف العميل، الحد اليومي ووسائل التواصل والمدة، التذكير والانتهاء، المفتاح والناس التانيين والأدمن).

## برنامج الولاء (FL-L09 — الدفعة ب) — 2026-11-02

**الفكرة:** كل محترف يحدد بنفسه **شرائح خصم** بتكبر مع عدد الشغل اللي خلّصه مع نفس العميل («بعد 2 شغلانة 5%، بعد 5 10%…»)، والخصم **بيتطبق لوحده** على عروض المشاريع والخدمات الجاهزة والاستشارات من غير ما العميل يطلب. قرارات المالك: الولاء = **خصم**، **على حساب المحترف** (بيتخصم من السعر اللي بيطلبه، والعمولة ورسوم العميل بتتحسب على السعر **بعد** الخصم زي أي مشروع)، على **سعر المشروع**، **لكل محترف لوحده**، ويشمل **كل حاجة**. ميزة الدولة `loyalty` (مقفولة لو مفيش قاعدة؛ لو اتقفلت الخصم يقف على طول). الجدول `freelance_loyalty_programs` (`freelancer_id` فريد، `enabled`، `tiers` JSON) + عمودين `list_price_minor` و`loyalty_bp` على `freelance_offers` و`freelance_consultations` (migration `2026_11_17_100000`). الخدمة `Services/Offers/Loyalty.php`.

**اللي بيتعد:** الشغل المخلّص مع نفس المحترف = اتفاقات `completed` + استشارات `completed` (نفس تاريخ العروض الخاصة). الشريحة = أعلى `after` وصله العميل.

**الحدود (سياسة، مجموعة `loyalty`):** `loyalty.max_discount_bp` (3000 = 30% افتراضي، نوع نسبة) · `loyalty.max_tiers` (4، من 1 لـ10). أقل خصم 1% (`MIN_BP = 100`) وأبعد شريحة بعد 50 شغلانة. الشرائح لازم تترتب: كل واحدة بعد اللي قبلها وبخصم أكبر. الأخطاء: `freelance_loyalty_tiers_required` · `_tiers_many` (ومعاه `max`) · `_after_invalid` · `_discount_invalid` · `_tiers_order` · `_not_set_up` (تشغيل برنامج ما اتحفظش).

**ينطبق فين (بتتحسب في السيرفر، التطبيق بيعرض بس):**
- **عرض مشروع:** `FreelancerOffers::send/edit` بيطبّق الخطوة على `client_id` بتاع المشروع؛ العرض بيحفظ `list_price_minor` (اللي كتبه المحترف) و`loyalty_bp`، و`price_minor` بعد الخصم. تعديل العرض بيبدأ من `list_price_minor`. `POST .../offers/preview` بيرجّع `loyalty{bp, after, works}|null` والتفاصيل محسوبة على السعر بعد الخصم.
- **خدمة جاهزة (مرة واحدة):** `GET services/{uuid}` للعميل فيه `loyalty` (البرنامج زي ما العميل شايفه) وكل مستوى `loyalty_price_minor`؛ `GET services/{uuid}/quote` بيرجّع `price_minor` بعد الخصم + `list_price_minor`, `loyalty_bp`, `saving_minor`؛ الطلب بيحفظ الخصم في `snapshot.loyalty` وفي العرض الداخلي المقبول. **الخدمات الشهرية برّه البرنامج** (سعر ثابت كل شهر).
- **استشارة:** `GET consultation-offers/{uuid}` فيه `options[]` بـ`price_minor` بعد الخصم + `list_price_minor`, `loyalty_bp`، و`loyalty`؛ الحجز بيحفظهم، والرد فيه `list_price_minor`, `loyalty_bp`, `saving_minor`.
- **العرض الخاص مالوش خصم فوقه:** سعره كلمة المحترف النهائية (مفيش تراكم).
- العميل بيشوف في ملف المحترف (`offers/{uuid}/freelancer`) نفس الكائن `loyalty`.

**المحترف** (`/api/freelance/v1/loyalty`، middleware `freelance.feature:loyalty`):
- `GET /` → `{available, currency, decimals, program{enabled, tiers[{after, discount_bp}]}|null, rules{min_discount_bp, max_discount_bp, max_tiers, max_after}, presets[{key, name, tiers}] (جاهزة ومقصوصة على الحدود), stats{past_clients, in_program, uses, saved_minor, by_tier[]}, clients[…] (أول 30، من غير وسائل تواصل)}`.
- `PUT /` `{enabled, tiers[{after, discount_bp}]}` (throttle) → نفس شكل `GET`.
- `POST enable` · `POST disable` (التشغيل محتاج برنامج محفوظ).

**العميل** (`/api/mobile/v1/freelance`، من غير مفتاح ميزة — لو اتقفلت في الدولة الرد بيبقى null):
- `GET loyalty` → كل محترف خلّصت معاه شغل وعنده برنامج شغال، الأعلى خصماً أولاً: `[{freelancer{uuid,name,headline,avatar_url}, works, tiers[{after, discount_bp, reached}], current{after,discount_bp}|null, next{after,discount_bp,remaining}|null, progress (0–1)}]`.
- `GET freelancers/{uuid}/loyalty` → `{loyalty: {works, tiers, current, next, progress}|null}` (الغلاف بيحوّل null لـ`[]` فبنلفّه).

**الإشعار:** العميل `loyalty.unlocked` (`{name, percent}`، الـdata فيها `loyalty` = uuid المحترف) لما شغلانة تخلص وتوصله **بالظبط** على `after` شريحة (من مستمعين على `FreelanceAgreement` و`FreelanceConsultation` لما تبقى `completed`). بيفتح «خصوماتي».

**الأدمن:** `GET admin/v1/freelance-loyalty[?search=&enabled=]` — قراءة بس، صلاحية **`freelance-loyalty.view`** (والعروض الخاصة `freelance-special-offers.view`، الاتنين في `AdminPermissionSeeder`). كل صف: `{id, freelancer, enabled, tiers, uses, saved_minor, currency, updated_at}`، و`totals` = `{freelancers, uses, saved[{currency, decimals, saved_minor}]}` (الموفّر لكل عملة). الصفحتين في لوحة الأدمن: «العروض الخاصة» و«برامج الولاء» (قايمة العمل الحر). الحدود في «الأسعار والقواعد» (مجموعة «الولاء») والمفتاح في «مفاتيح الخصائص».

**اختبار:** `tests/Feature/FreelanceLoyaltyTest.php` (7 اختبارات، 188 تأكيد: الحدود والتحقق، خصم عرض المشروع مع التعديل ورؤية العميل والغريب، طلب الخدمة والاستشارة والإحصائيات والأدمن، عدم التراكم مع العرض الخاص واستبعاد الشهرية، خطوات العميل وإشعار الفتح، البرنامج مقفول ومفتاح الدولة، التقريب).

## حقوق الملكية والتراخيص (FL-L13) — 2026-11-02

**الفكرة:** المحترف بيقول في **كل عرض وكل خدمة جاهزة** مين صاحب الشغل بعد الدفع وإيه اللي يقدر العميل يعمله بيه. 3 نماذج: `transfer` (نقل كامل بعد الدفع — **الافتراضي**) · `license` (ترخيص استخدام بشروط) · `retained` (المحترف محتفظ بالملكية والعميل بياخد حق استخدام). الشروط بتتنقل من العرض/الخدمة للاتفاق **لقطة ثابتة** مع **النص القياسي بتاع اليوم ده** (من الأدمن، لكل دولة وبالعربي والإنجليزي)، فتعديل النص بعد كده يوصل للاتفاقات الجديدة بس. الميزة مفتاحها `rights` لكل دولة (`freelance.feature:rights`)؛ لو مقفولة العروض والاتفاقات القديمة بتشتغل زي ما كانت و`rights` فيها `null`. الاستشارات مش داخلة (مفيهاش ملفات). التوقيع الإلكتروني خارج النطاق.

**شكل الشروط (`rights`، في الطلب وفي رد العرض/الخدمة):** `{model: transfer|license|retained, commercial: bool, exclusive: bool, term_months: int|null (1–120), territory: worldwide|country, portfolio: bool}`. الأخطاء: `freelance_rights_model_invalid` · `freelance_rights_territory_invalid` · `freelance_rights_term_invalid` (422). **`transfer`** بيتجاهل أي حدود (كله تجاري، حصري، للأبد، العالم). **`retained`** مفيهوش حصري ولا مدة. لو مفيش `rights` في الطلب = الافتراضي (`transfer`). `portfolio` هنا **أمنية المحترف** بس؛ موافقة العميل منفصلة (تحت).

**المحترف** (`/api/freelance/v1`):
- `GET rights` → `{enabled, default{…}, models[{model, label, summary, terms{title, body, version}}] (النص القياسي لدولته بلغة الطلب), limits{max_term_months, max_materials}}`.
- العرض: `POST projects/{uuid}/offers` و`PUT offers/{uuid}` بيقبلوا `rights{…}` (التعديل لو الشروط زي ما هي = `freelance_offer_unchanged`). رد العرض فيه `rights` ملخّص `{model, model_label, summary, clauses[], details{commercial, exclusive, term_months, territory}, portfolio}` (`null` لو الميزة مقفولة).
- الخدمة: `POST/PUT services` بيقبلوا نفس `rights`؛ الطلب بيورّث الشروط للعرض فللاتفاق. الخدمة اللي اتعملت من غير شروط = الافتراضي.
- **التسليم:** `POST projects/{uuid}/milestones/{m}/deliver` (multipart) فيه `rights_confirmed` (لازم true) و`materials[i][name|source|license]` (خامات من برّه، حد أقصى `max_materials`). من غير الإقرار = `freelance_rights_declaration_required` (422) وبدون ما يتسجل تسليم. الاتفاقات اللي من غير شروط ما بتطلبش إقرار. الإقرار والخامات بيتحفظوا مع التسليم وبيظهروا للعميل في `deliveries[].rights{confirmed_at, materials[]}`.
- **المعرض:** `GET rights/portfolio-sources` → شغلي المخلّص اللي العميل سمح بعرضه `[{uuid (الاتفاق), title, completed_at, used}]`. `POST profile/portfolio` و`PUT` بيقبلوا `agreement_uuid` (يربط العنصر بالشغلانة)؛ أخطاء: `freelance_rights_portfolio_source_invalid` · `_not_finished` · `_not_allowed` (422). العنصر في رد المحترف فيه `agreement` (uuid) و`withdrawn_by_client` (العميل سحب السماح → العنصر بيختفي من العرض العام ويفضل عند صاحبه).
- `POST projects/{uuid}/agreement/rights/portfolio-request` (throttle) → بيبعت إشعار للعميل يطلب السماح؛ مش قبل `ask_again_days` (3) = `freelance_rights_portfolio_asked_recently` (429)، ولو مسموح أصلاً `freelance_rights_portfolio_already_allowed` (409). بيرجّع الاتفاق.

**الاتفاق** (للطرفين، `GET …/agreement` و`GET …/agreement` عند المحترف): `rights` = `{model, model_label, summary, clauses[], details, terms{title, body, version}, portfolio{wanted, allowed, asked_at}, parts{total, granted}, state: pending|partial|granted, can{portfolio_toggle (العميل), portfolio_request (المحترف)}}` أو `null`. **الحقوق بتنتقل جزء جزء:** الجزء `Approved` (اتعتمد واتدفع) = حقوقه عدّت (`parts.granted`)، وقبل كده العميل **بيعاين ويراجع بس**. لما جزء يتعتمد بيتسجل `rights.granted` في سجل الموافقات (`freelance.trail.rights_granted`) والعميل بيتبلّغ.

**العميل** (`/api/mobile/v1/freelance`):
- العروض والخدمات: نفس `rights` الملخّص (`GET projects/{uuid}/offers`، `GET services/{uuid}`، …).
- `PUT projects/{uuid}/agreement/rights/portfolio {allowed: bool}` (`freelance.feature:rights`، throttle) → السماح/سحب السماح بعرض الشغل المخلّص في معرض المحترف؛ بيرجّع الاتفاق، والمحترف بيتبلّغ، والحدث في السجل (`rights.portfolio_granted/revoked`). السماح دايماً بيبدأ **false**.
- `POST rights/claims {subject_type: portfolio_item|service, subject: uuid, details (10–2000)}` → بلاغ إن العمل مش بتاع المحترف (201 `{uuid, status}`)؛ مكرر = `freelance_rights_claim_exists` (409)، العمل مش ظاهر = `freelance_rights_claim_subject_not_found` (404).
- نزاع بسبب `rights` (فئة `rights`): `POST …/milestones/{m}/dispute {reason: rights, details}`.

**من برّه دُرر (من غير تسجيل):** `POST /api/freelance/v1/public/rights-claims {subject (uuid عنصر المعرض), contact, name?, details (20–2000)}` (throttle 5/10 دقايق).

**الأدمن** (`/api/admin/v1/freelance-rights`، صلاحية `freelance-rights.view|update` في `AdminPermissionSeeder`): `GET overview` (الاتفاقات حسب النموذج، المعرض: مطلوب/مسموح/عناصر، نزاعات الحقوق، البلاغات) · `GET terms[?country_id=]` (3 صفوف + `countries` في الميتا) · `PUT terms` `{country_id?, model, title_ar, title_en, body_ar, body_en}` (كل حفظ بيزوّد `version`) · `DELETE terms/{id}` (رجوع للنص الافتراضي) · `GET claims[?status=]` + `counts` · `GET claims/{uuid}` · `POST claims/{uuid}/decide {decision: uphold|dismiss, note?}`. القبول بيخفي عنصر المعرض (أو بيوقف الخدمة) وبيبلّغ المبلّغ لو عميل؛ القرار مرتين = `freelance_rights_claim_closed` (409). تفاصيل النزاع في الأدمن (`freelance-disputes/{uuid}`) فيها `agreement.rights` بالشروط اللي اتفق عليها الطرفين.

**الإشعارات** (الأنواع `freelance_rights_*`، الـdata فيها `project` = uuid المشروع): `rights.granted` (للعميل، + `milestone`) · `rights.portfolio_requested` (للعميل) · `rights.portfolio_granted/revoked` (للمحترف) · `rights.claim_closed` (للعميل، الـdata فيها `rights_claim`).

**حدود معروفة:** التسوية الجزئية والاسترداد **ما بيمنحوش** حقوق · لا يمكن منع تصوير الشاشة بكاميرا تانية (القفل على الموبايل بيمنع اللقطة والتسجيل بس) · العروض الخاصة من نوع شغل مخصص بتاخد الشروط الافتراضية · النصوص القياسية قوالب استرشادية ومحتاجة مراجعة قانونية لكل دولة قبل الإطلاق.

**معاينة بس قبل الدفع:** للعميل، طول ما الجزء مش `Approved` (واتفاقه فيه شروط): `deliveries[].files[].preview_only = true`، و`GET …/deliveries/{d}/files/{media}` بيرجّع **صورة JPEG مصغّرة (أطول ضلع 800px، من غير علامة مائية، `Cache-Control: private, no-store`)** للصور، وأي نوع تاني = `freelance_rights_preview_only` (403). المحترف دايماً بياخد الملف كامل، وبعد الاعتماد العميل بياخد الملف كامل. تطبيق دُرر بيمنع لقطة الشاشة في قسم الشغل (Android `FLAG_SECURE`، iOS إخفاء المحتوى وقت التصوير).

**اختبار:** `tests/Feature/FreelanceRightsTest.php` (14 اختبار، 208 تأكيد).

---

## المجتمع المهني (FL-L12) — 2026-11-02

**الفكرة (قرارات المالك):** فيد منشورات بشكل LinkedIn **للمحترفين بس** (العميل ما يشوفوش)، جوه تطبيق المحترف بس، ومالوش علاقة بالشات ولا بمجتمعات الشات. الأكاديمية اتشالت. الفيد **لكل دولة** (المحترف بيشوف منشورات دولته). مفتاح الدولة `community` (`freelance.feature:community`). الكتابة (نشر/تعليق/تفاعل) محتاجة حساب **موثّق** ومن غير كتم من الإدارة؛ القراءة لأي محترف داخل.

**الحماية:** ممنوع وسائل التواصل في المنشور والتعليق بنفس قاعدة الشات (`ContactGuard`: إيميل/لينك/رقم/حساب سوشيال) = `freelance_community_contact_blocked` (422، `data.found`). حد يومي (`config freelance.community`): 5 منشورات و60 تعليق و20 بلاغ في 24 ساعة (429: `freelance_community_daily_posts|_comments|_reports`). لما **3 أعضاء مختلفين** يبلّغوا عن حاجة بتتخفى لوحدها لحد ما الإدارة تقرر (`hidden.under_review = true`). الإدارة تقدر تكتم عضو (`freelance_community_muted` 403، `data.until`) لحد 90 يوم.

**المحترف** (`/api/freelance/v1/community`، الصفحات بـ`cursor` و`meta.next_cursor` + `meta.has_more`):

| Method | Path | ملاحظات |
|---|---|---|
| GET | `community` | `{me{uuid,name,headline,bio,avatar_url,badge,specialties[],posts,followers,following,saved}, can_write, muted{until,reason}\|null, limits{body_min,body_max,comment_max,max_images,posts_per_day,posts_today,comments_per_day,comments_today}, kinds[{key,label}], reactions[{key,label}], report_reasons[{key,label}], rules[], specialties[{id,name}], my_specialties[]}` |
| GET | `community/feed?tab=for_you\|following\|specialty\|mine\|saved&kind=post\|question\|work&specialty_id=&search=&member=<uuid>&cursor=&per_page=` | قايمة منشورات (شكل المنشور تحت) |
| POST | `community/posts` (multipart) | `kind` (post افتراضي)، `body` (10–3000)، `specialty_id?` (الافتراضي تخصصي الأساسي)، `portfolio_item` (uuid، لازم مع `work`: عنصر من معرضي ظاهر)، `images[]` (لحد 4، jpg/png/webp، 5MB) → 201 منشور |
| GET | `community/posts/{uuid}` | `{post, comments[]}` + `meta.next_cursor` (أفضل إجابة الأول ثم الأقدم، وكل تعليق فيه `first_replies[]` لحد 3) |
| PUT | `community/posts/{uuid}` | `{body?, specialty_id?, remove_images[]?}` (صاحبه بس) |
| DELETE | `community/posts/{uuid}` | صاحبه بس |
| GET | `community/posts/{uuid}/comments?cursor=` | تعليقات أكتر |
| POST | `community/posts/{uuid}/comments` | `{body (≤1500), parent? (uuid تعليق — الرد على رد بيتحط تحت نفس التعليق: مستوى واحد)}` → 201 تعليق |
| POST | `community/posts/{uuid}/accept` | `{comment: uuid\|null}` أفضل إجابة على **سؤال** (صاحبه بس) |
| POST/DELETE | `community/posts/{uuid}/save` | حفظ / شيل من المحفوظات |
| PUT | `community/{posts\|comments}/{uuid}/reaction` | `{kind: like\|celebrate\|insightful\|support\|null}` → `{reactions, my_reaction, top[]}` |
| GET | `community/comments/{uuid}/replies?cursor=` | الردود |
| PUT/DELETE | `community/comments/{uuid}` | `{body}` تعديل / مسح (صاحبه) |
| GET | `community/members/{uuid}` | كارت العضو: `{uuid,name,headline,bio,avatar_url,badge,verified,specialties[],posts,followers,following,is_me,is_following}`؛ منشوراته من `feed?member=` |
| POST/DELETE | `community/members/{uuid}/follow` | متابعة / إلغاء → كارت العضو |
| GET | `community/members/{uuid}/followers\|following?cursor=` | `[{uuid,name,headline,avatar_url,badge,is_me}]` |
| POST | `community/reports` | `{subject_type: post\|comment, subject: uuid, reason: spam\|contact\|abuse\|off_topic\|misleading\|other, details?}` → 201؛ مكرر = 409 `freelance_community_report_exists`، على حاجتي = 422 `_report_own` |

**شكل المنشور:** `{uuid, kind, kind_label, body, author{uuid,name,headline,avatar_url,badge,is_me,is_following}, specialty{id,name}|null, images[{id,url}] (روابط موقّعة 30 دقيقة), work{uuid,title,image_url}|null, reactions, top_reactions[], my_reaction, comments, saved, accepted_comment (uuid|null), hidden{reason,under_review}|null (صاحبه بس بيشوف المخفي), edited, created_at, can{edit,delete,report,accept}}`.
**شكل التعليق:** `{uuid, body, author{…}, parent, reactions, my_reaction, replies, accepted, hidden, edited, created_at, can{edit,delete,report}, first_replies[]?}`.

**الإشعارات للمحترف** (`freelance_community_*`، الـdata فيها `community_post` / `community_comment` / `community_member`): `community.commented` · `community.replied` · `community.followed` · `community.answer_accepted` · `community.hidden` · `community.muted`. التطبيق بيعيد تحميل المنشور لما يفتح الإشعار، **مفيش polling** (سحب للتحديث + تحميل مرة عند الفتح).

**الأدمن** (`/api/admin/v1/freelance-community`، صلاحية `freelance-community.view|update`): `GET overview` (`posts{total,week,hidden}`, `comments{total,week}`, `members{posting_month,muted}`, `reports{open,upheld,dismissed}`) · `GET reports[?status=]` + `counts` · `GET reports/{uuid}` · `POST reports/{uuid}/decide {decision: uphold|dismiss, note?, mute_days? (0–90)}` (القبول بيخفي ويقفل باقي البلاغات على نفس الحاجة، والرفض بيرجّع اللي اتخفى بالبلاغات لما مايبقاش فيه بلاغ مفتوح) · `GET posts[?status=&kind=&search=&country_id=]` · `GET posts/{uuid}` (بكل التعليقات) · `POST {posts|comments}/{uuid}/hide {reason}` · `POST {posts|comments}/{uuid}/unhide` · `GET mutes` · `POST mutes {freelancer_id, days, reason}` · `DELETE mutes/{id}`.

**الواجهات:** تطبيق المحترف (تفاصيل في [APP.md](APP.md)) وصفحة الأدمن «المجتمع المهني» (`views/freelance/community`). **اختبار:** `tests/Feature/FreelanceCommunityTest.php` (10 اختبارات).

**الجداول:** `freelance_community_posts` (+ صور media `images` على الديسك الخاص) · `_comments` · `_reactions` · `_saves` · `_follows` · `_reports` · `_mutes` — migration `2026_11_19_100000`.

---

## جلسة فيديو الاستشارة (FL-L16) — 2026-11-02

**قرارات المالك:** الجلسة فيديو LiveKit (نفس بنية مكالمات الشات)، **التسجيل إجباري** (العميل بيوافق وهو بيحجز، والمحترف وهو بيقبل، والعرض الخاص = موافقة المحترف)، التسجيل بيترفع لـbucket (S3-compatible، إعدادات `CHAT_RECORDINGS_*`) وبعدين **بيتنقل للـstorage الخاص بتاعنا ويتمسح من الـbucket**، والطرفين بينزّلوه، **وبيتمسح بعد أسبوع** من نهاية الجلسة؛ لو فيه شكوى بيتحفظ لحد حكم الأدمن ويتمسح بعد أسبوع من الحكم. الأدمن بيشوف الفيديو في الشكوى وكل مشاهدة/تنزيل بيتسجل. تطبيق المحترف فيه نسخة من كود المكالمة. التجربة على الموبايل مؤجلة.

**الحجز:** `POST mobile/v1/freelance/consultations` و`POST special-offers/{uuid}/accept` (استشارة) بقوا محتاجين `recording_consent: true`، من غيره = `freelance_consultation_recording_consent_required` (422).

**الغرفة:** `consult-{uuid}` (اتنين بالكتير)، بتفتح قبل الميعاد بـ`open_before_minutes` (5) لحد `ends_at`، والسيرفر بيقفلها في الآخر (بيطلّع الكل). الخروج بالغلط مش مشكلة: أي طرف يرجع طول ما الوقت موجود. الأدمن يقدر يقفلها بدري ويفتحها تاني لو لسه فيه وقت.

**الطرفين** (العميل `mobile/v1/freelance/consultations/{uuid}/…`، المحترف `freelance/v1/consultations/{uuid}/…`):

| Method | Path | ملاحظات |
|---|---|---|
| GET | `{uuid}/session` | حالة الجلسة (شكلها تحت)؛ وهي كمان جوه رد الاستشارة في `session` |
| POST | `{uuid}/session/join` | `{url, token, room, identity, starts_at, ends_at, server_time, warn_before_end_minutes, recording (bool), other{side,name,in_room}}`؛ التوكن صالح لحد `ends_at`. أخطاء (409): `freelance_consultation_session_not_yet` (`data.opens_at`) · `_session_over` · `_session_closed` (الأدمن) · `_session_not_booked`؛ LiveKit مش متظبط = `_session_unavailable` (503) |
| POST | `{uuid}/session/connected` | التطبيق دخل الغرفة فعلاً: بيبدأ الحضور، والتسجيل لو مش شغال، والطرف التاني بيوصله إشعار أول مرة |
| POST | `{uuid}/session/leave` | التطبيق خرج؛ لو الاتنين برّه جزء التسجيل بيخلص والدخول تاني بيبدأ جزء جديد |
| POST | `{uuid}/no-show` (العميل بس) | بعد الميعاد بـ`no_show_minutes` (10) ولو المحترف ما دخلش خالص → شكوى للإدارة بسجل الحضور؛ غير كده `freelance_consultation_no_show_invalid` (409) |

**شكل `session`:** `{available, room_open, opens_at, starts_at, ends_at, closed{by: time|staff, at}|null, recorded: true, recording_available (الـbucket متظبط), consent{client, freelancer}, in_room{client, freelancer}, attendance[{side, joined_at, left_at}], recordings[{uuid, part, status: recording|processing|moving|stored|deleted, duration_seconds, size_bytes, started_at, stopped_at, download_url, watch_url}], recordings_delete_at, recordings_held, recordings_deleted_at, can{join, no_show, close, reopen}}`. `download_url` / `watch_url` روابط موقّعة 15 دقيقة لنفس الشخص (`GET freelance/v1/consultation-recordings/{uuid}?viewer=&viewer_id=&action=download|watch&signature=…`)، الـwatch بيدعم Range للمشغّل، وكل استخدام بيتسجل. **مفيش polling:** التطبيق بيعرف النهاية من `ends_at` بساعة الجهاز (مع `server_time`)، والإشعارات بتقول «الطرف التاني دخل» و«الجلسة هتخلص بعد 5 دقايق» و«التسجيل جاهز».

**الإشعارات** (للطرفين، `data.consultation` = uuid): `consultation.session_joined` · `consultation.session_ending` · `consultation.session_closed` · `consultation.session_reopened` · `consultation.recording_ready` (فيه تاريخ المسح).

**الأدمن** (`admin/v1/freelance-consultations/{uuid}/…`، صلاحية `freelance-consultations`): `GET session` (نفس الشكل بروابط watch باسم الأدمن + `views[{part, viewer, viewer_id, action, at}]`) · `POST session/close` · `POST session/reopen` (update).

**الساعة:** `freelance:sync-consultation-sessions` كل دقيقة: تنبيه قبل النهاية، قفل الغرف اللي وقتها خلص، إعادة محاولة التسجيل لو حد جوه ومفيش تسجيل، متابعة LiveKit، نقل الملف من الـbucket لـ`storage/app/private/freelance/consultation-recordings/{consultation}/{part}.mp4` (`local`) ومسحه من الـbucket، ومسح الملفات بعد أسبوع.

**من غير bucket:** الجلسة بتشتغل عادي من غير تسجيل (`recording_available = false`) لحد ما `CHAT_RECORDINGS_*` تتملي.

**الجداول:** أعمدة جديدة في `freelance_consultations` (`recording_consent_at`, `freelancer_consent_at`, `room_name`, `session_opened_at`, `session_closed_at`, `session_closed_by`, `ending_told_at`, `recordings_delete_at`, `recordings_deleted_at`) · `freelance_consultation_attendance` · `freelance_consultation_recordings` · `freelance_consultation_recording_views` — migration `2026_11_20_100000`.

## رئيسية تطبيق المحترف وبانرات الأدمن (إعادة التصميم — الدفعة ب) — 2026-11-02

- **المحترف:** `GET freelance/v1/home` (ميزة `freelance`) بيرجع الرئيسية في طلب واحد:
  - `features` (سويتشات الدولة، عشان التطبيق يخفي التاب أو الكارت اللي ميزته مقفولة).
  - `promos[]`: `{uuid, title, body, cta, image_path, target_type: none|screen|url, target}` باللغة الحالية.
  - `stats`: `currency, decimals, earned_month_minor` (أرباح اتصرفت من أول الشهر)، `pending_minor` (أرباح لسه محجوزة)، `live_offers` (submitted/viewed/shortlisted، من غير عروض الخدمات الجاهزة)، `active_projects`، `rating` (null لحد ما التقييمات تكفي)، `reviews_count`، `completed_projects`.
  - `attention`: `orders_awaiting` (لو ميزة `services`)، `consultations_pending` (لو `consultations`)، `invitations_pending` (لو `invitations`)، `revisions` (مراحل رجعت للتعديل في اتفاقات شغالة).
  - `next_consultation`: طلب مستني ردي الأول، وإلا أقرب استشارة محجوزة لسه ماخلصتش: `{uuid, status, title, minutes, client_name, starts_at, ends_at}` أو `null`.
  - `work[]` (لحد `freelance.home.work_max` = 3): الاتفاقات الشغالة بالمرحلة الحالية `{project_uuid, title, client_name, milestone: {title, status, due_at}}`، الأقرب ميعادًا الأول (`due_at` = بداية المرحلة + أيامها).
  - التطبيق بيقراه عند الفتح ورجوع الاتصال ووصول إشعار بس (مفيش مؤقت).
- **البانر بيظهر لو:** شغال، وبين `starts_at` و`ends_at` (الفاضي = من غير حد)، ودولته هي دولة المحترف أو من غير دولة، ولو بيفتح صفحة فميزتها مفتوحة في الدولة. الترتيب `sort_order` ثم الأحدث، ولحد `freelance.home.promos_max` = 6.
- **الصفحات المسموحة (`target` لـ `screen`):** `projects, invitations, plans, insights, services, consultations, special_offers, loyalty, community, skill_tests, verification, coach, portfolio, wallet, availability, capacity`. الـ `url` لازم `https`.
- **الأدمن** (مجموعة صلاحيات `freelance-promos`: `view / create / update / delete`، وكل تغيير بيتسجل في الـ audit):
  - `GET admin/v1/freelance-promos[?state=showing|scheduled|ended|off&country_id=&search=&per_page=]` → كل بانر بـ `state`، و`screens` في الرد.
  - `POST admin/v1/freelance-promos` (multipart): `title[ar|en]` (إجباري، 80)، `body[ar|en]` (160)، `cta[ar|en]` (30)، `target_type`، `target`، `country_id?`، `starts_at?`، `ends_at?` (بعد البداية)، `sort_order` (0–1000)، `is_active`، `image?` (jpg/png/webp لحد 2MB، على الـ public disk).
  - `GET|POST admin/v1/freelance-promos/{uuid}`: التعديل multipart، و`remove_image=1` بيشيل الصورة.
  - `POST …/{uuid}/toggle` و`DELETE …/{uuid}`.
- **الجدول:** `freelance_promos` (migration `2026_11_21_100000`). الاختبار: `FreelancerHomeTest` (5).

## رئيسية فريلانس العميل وجمهور البانرات (إعادة تصميم العميل — الدفعة ب) — 2026-11-03

- **العميل:** `GET mobile/v1/freelance/home` (حارس `user_api`) بيرجع المحور في طلب واحد:
  - `service`، `features` (سويتشات دولة العميل)، `currency`، `decimals`.
  - `promos[]`: نفس شكل بانرات المحترف، بس اللي جمهورها `client` أو `both`.
  - `stats`: `running_projects` (منشور/اتعيّن/شغال)، `new_offers` (عروض `submitted` على مشاريعي المنشورة)، `running_orders` (طلبات خدمات مستنية أو شغالة، لو `services`)، `upcoming_consultations` (pending/booked لسه ماخلصتش، لو `consultations`)، `funded_month_minor` (المراحل اللي اتموّلت من أول الشهر).
  - `attention`: `new_offers`، `to_review` (تسليم مستني اعتمادي)، `to_fund` (اتفاق شغال فيه مرحلة مش ممولة ومفيش حاجة تانية شغالة — نفس قاعدة `next_action`)، `to_fix` (مشروع رجع للتعديل أو اترفض): كل واحد `{count, project_uuid}` (مشروع الأحدث)؛ و`special_offers` (عدد).
  - `next_consultation`: أقرب محجوزة لسه ماخلصتش، وإلا أقرب مستنية المحترف: `{uuid, status, title, minutes, freelancer_name, starts_at, ends_at}` أو `null`.
  - `projects[]` (لحد `freelance.home.client_projects_max` = 3): مشاريعي الجارية بنفس شكل قائمة المشاريع (الأحدث تعديلًا).
  - `services[]` (لحد `freelance.home.client_services_max` = 8، لو `services`): كروت الخدمات الجاهزة بترتيب «موصى به».
  - `is_new`: مفيش أي مشروع ولا طلب لسه.
  - التطبيق بيقراه عند الفتح والسحب للتحديث ووصول إشعار فريلانس ورجوع الاتصال بس (مفيش مؤقت).
- **جمهور البانر:** عمود `audience` في `freelance_promos` (`freelancer` | `client` | `both`، الافتراضي `freelancer` فالبانرات القديمة فضلت للمحترف) — migration `2026_11_22_100000`. كل تطبيق بيعرض بتاعه + `both`.
- **صفحات العميل (`target` لـ `screen` لما الجمهور `client`):** `projects, new_project, services, orders, consultations, my_consultations, special_offers, loyalty, favorites`. للجمهور `both` الصفحات اللي في الاتنين بس: `projects, services, consultations, special_offers, loyalty`.
- **الأدمن:** `audience` في الإضافة والتعديل (تغيير الجمهور لوحده بيعيد التحقق من الصفحة)، وفلتر `?audience=` في القائمة، و`screens_by_audience` في الرد (و`screens` فضلت للمحترف زي ما هي).
- **الاختبار:** `FreelanceClientHomeTest` (5).

## قائمة مشاريع العميل: شغالة / منتهية (إعادة تصميم العميل — الدفعة ج) — 2026-11-03

- `GET mobile/v1/freelance/projects?scope=active|ended[&status=]`: `active` = كل الحالات غير المنتهية (مسودة لحد شغال)، `ended` = `closed` و`rejected` و`cancelled`. الـ`status` بيضيّق جوه الجزء. قيمة تانية = 422. من غير `scope` الرد زي ما كان.
- الاختبار: `FreelanceClientProjectsTest::test_the_list_splits_running_and_ended_projects`.

## عملة واحدة (الدولار) والمحفظة الدولية (المرحلة A) — 2026-11-04

**القرار (المالك):** كل فلوس الفريلانس بعملة واحدة، الدولار، أيًا كانت دولة العميل أو المحترف. المحترف عنده محفظة واحدة بس (دولية بالدولار)، والعميل عنده محفظته المحلية + محفظة دولية بالدولار يشحنها ويسحب منها ويدفع منها شغل الفريلانس، ومينفعش يحوّل منها لحد. الشحن بنفس طرق الشحن المحلية **ومن غير سعر صرف**: البوابة بتتشحن بالدولار مباشرة.

- **الإعداد:** `config('freelance.money')` = `{currency: USD (env FREELANCE_CURRENCY), country_code: INT, country_name, flag_code: un}`.
- **الصف الدولي:** صف في `countries` كوده `INT` وعملته USD و`status = false` (فمش بيظهر في أي قائمة دول ولا بيتحل لطلب). بيتعمل أول ما يتطلب (`FreelanceMoney::country()`) وبالـ migration `2026_11_23_100000_create_freelance_international_wallet`. إنشاؤه بيعمل له `wallet_settings` (التحويل مقفول) عن طريق `WalletSettingObserver`.
- **`FreelanceMoney`** (`Services/Policy/FreelanceMoney.php`): `country()` · `currency()` · `code()` · `decimals()` · `format($minor)` · `wallet($owner)` (المحفظة الدولية، بتتعمل أول مرة) · `existingWallet($owner)` · `meta()`. أي كود بيحرّك أو بيعرض فلوس فريلانس بيسأل هنا بدل عملة الدولة.
- **المحفظة:** الـ middleware `UseFreelanceWallet` (بعد `country`) بيبدّل دولة الطلب بالصف الدولي، فكل شاشات المحفظة الموجودة بتشتغل زي ما هي على الدولار:
  - المحترف: `/api/freelance/v1/wallet/*` كلها (الرصيد، الكشف، الـ PIN، الشحن، الـ checkout، السحب) بقت على المحفظة الدولية.
  - العميل: **جديد** `/api/mobile/v1/freelance/wallet/*` = نفس مسارات `mobile/v1/wallet/*` (الرصيد، الكشف، الـ PIN، `payment-methods`، `topups`، `checkouts`) + السحب (`withdrawal-methods`، `withdrawals`) — **من غير مسارات تحويل**. `mobile/v1/wallet/*` فضلت المحفظة المحلية زي ما هي.
  - `GET wallet` → `other_wallets` بقت تعرض محافظ الدول المفعّلة بس (مش الصف الدولي).
- **الدفع:** تمويل المراحل وحجز الاستشارات (`MilestonePayments`، `Consultations`) من المحفظة الدولية للعميل، وتحرير الأرباح (`FreelanceEarnings`) للمحفظة الدولية للمحترف، والاشتراكات وحزم العروض بتتخصم من المحفظة الدولية للمحترف. الدفتر المالي (`FreelanceLedger`) بيتسجّل بالدولار ودولة الشغل نفسها (عشان تقارير الدول).
- **العملة في الردود:** `currency_code` للمشاريع والعروض والاتفاقات والخدمات والاستشارات والعروض الخاصة الجديدة = `USD`. `GET config` و`GET mobile/v1/freelance/home` و`GET home` للمحترف بيرجّعوا `USD`. `mobile/v1/freelance/home` فيه كمان **`wallet_minor`** (المتاح في المحفظة الدولية).
- **السياسات (الأسعار والقواعد):** مجموعة واحدة للعالم كله على الصف الدولي. `FreelancePolicies::for($countryId, …)` بيتجاهل الدولة (`scope()`). صفحة الأدمن بتعرض نطاق «دولي» بس، و`copy` بقى لا يعمل شيء (`copied: 0`). مفاتيح الخصائص (`freelance_feature_rules`) فضلت **لكل دولة** زي ما هي.
- **الباقات والحزم:** سعر واحد لكل باقة وحزمة بالدولار على الصف الدولي (Plus 9$ / 86$ سنوي، Pro 24$ / 230$ سنوي، الحزم 3$ / 6$ / 12$). صفحات أسعار الأدمن بتعرض صف «دولي» بس، وتحديد سعر لدولة تانية → 404.
- **البيانات القديمة:** مفيش تحويل: المشاريع والاتفاقات القديمة بعملتها (SAR) لسه بعملتها، وتمويلها من المحفظة الدولية بيرفض بـ `wallet_currency`. الأرصدة المحلية القديمة للمحترفين فضلت في محافظهم المحلية. الأسعار والسياسات القديمة لكل دولة فضلت في الجداول بس مش بتتقري.
- **الاختبارات:** `phpunit.xml` بيحط `FREELANCE_MONEY_COUNTRY=SA`، فاختبارات الفريلانس القديمة بتشغّل محفظتها الوحيدة على صف السعودية بتاعها (نفس مسارات الكود). السيناريو الحقيقي (صف `INT` بالدولار، محفظتين للعميل، مفيش تحويل، الدفع من الدولار، سياسات وأسعار واحدة) في `FreelanceInternationalWalletTest` (5).
- **الأدمن:** `GET general/v1/countries/dropdown?with_international=1` بيضيف الصف الدولي (صفحتي طرق الدفع وقواعد الرسوم في المحفظة)، و«إعدادات المحفظة» بتعرض صفه زي أي دولة (حدود الشحن والسحب).

## فتح الدول (المرحلة B) — 2026-11-04

**القرار (المالك):** المحترف مش مربوط بدولته: بيشوف المشاريع والخدمات والاستشارات والمنشورات من أي دولة، والعميل بيختار أي محترف من أي دولة. **مفاتيح الخصائص فضلت لكل دولة زي ما هي**: المحتوى بيظهر من كل دولة الخدمة (والميزة) مفتوحة فيها، ومحتوى دولة مقفولة مش بيظهر لحد.

- **`FreelanceFeatures::openCountries(?feature)`**: الدول اللي `freelance` مفتوح فيها (ومش مقفولة من «توفر الخدمات»)، ومعاها الميزة لو اتبعتت.
- **اتفتح:**
  - تصفّح المشاريع للمحترف (`FreelancerProjects::visible`): المنشورة العامة من `openCountries('projects')` بدل دولة المحترف (القائمة وصفحة المشروع والمفضلة).
  - إشعار المشاريع المناسبة (`ProjectMatching`): محترفين من `openCountries()`.
  - الدعوات (`ProjectInvitations`): «ادعُ محترفين» والدعوة نفسها لمحترفين من أي دولة مفتوحة.
  - الخدمات الجاهزة للعميل (`ServiceCatalog`): من `openCountries('services')`.
  - الاستشارات للعميل (`ConsultationOffers::market` و`bookable`): من `openCountries('consultations')`.
  - المجتمع (`Community::visible`): المنشورات من `openCountries('community')`.
- **الدولة في الردود:** `country: {code, name}` (`FreelanceCountry::card`) في كارت المشروع للمحترف، وكارت الخدمة، وكارت الاستشارة، والمنشور. التطبيقات بتعرض علم الدولة واسمها.
- **فضل لكل دولة:** المفاتيح، وتوثيق المحترف ومستنداته (حسب دولته)، والنص القياسي للحقوق، وترقيم الإيصالات، وتقارير الأدمن.
- **الاختبار:** `FreelancerBrowseProjectsTest` · `FreelanceProjectMatchingTest` · `FreelanceCommunityTest` · `FreelanceServicesTest::test_a_client_of_another_open_country_finds_the_service_with_its_country` · `FreelanceInvitationsTest` (دولة مقفولة مش بتظهر).
