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
| `invitation.received` · `offer.accepted` · `offer.rejected` · `milestone.approved` · `milestone.revision` · `payment.held` · `payment.released` · `verification.approved` · `verification.rejected` · `dispute.opened` · `dispute.resolved` · `quota.low` | `offer.received` · `milestone.delivered` · `dispute.opened` · `dispute.resolved` · `review.received` |

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
- المحترف (`freelance/v1`): `GET invitations` (`page`/`per_page`/`status=sent|accepted|declined`)، `POST invitations/{uuid}/accept|decline` (يتطلب حساب موثّق)؛ `GET projects/{uuid}` فيه `invitation{uuid,status}`.
- المشروع الخاص يظهر للمدعوّين فقط؛ التقديم من غيرهم `freelance_project_invite_only`.
- أخطاء: `invitations_limit`, `invitation_not_open`, `invitation_closed`, `invitation_project_public`, `invitation_project_not_published`, `invitation_accepted_exists`.
- العميل يختار عرضًا واحدًا بالطريقة العادية؛ لو محدش قبل يقدر يحوّل المشروع لعام.

## نسخ المشروع والحد الأقصى للتكلفة والمعاينة ومشروعي (FL-209 / FL-210 / FL-211)

على `mobile/v1/freelance/projects` (سويتش `projects`):

| الطريقة | المسار | ملاحظات |
|---|---|---|
| POST | `{uuid}/duplicate` | (FL-209) مسودة جديدة بنفس العنوان والوصف والتخصص والمهارات النشطة والميزانية والحد الأقصى والمدة والظهور والأسئلة والملفات (نسخ مستقل). مفيش عروض ولا دعوات ولا اتفاق. أي مشروع يتنسخ مهما كانت حالته. بيحترم حد المشاريع المفتوحة (`freelance_project_limit`). الحدث `project.created` فيه `copied_from`. throttle `freelance-project-create` |
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
- مفيش مكالمة في شات الفريلانس؛ الكلام في الغرفة نفسها (NEEDS-DECISION: مكالمة لو اتطلبت).

## التسليم بملفات والمعاينة وقائمة القبول (FL-601 → FL-604)

- المحترف: `POST freelance/v1/projects/{uuid}/milestones/{milestone}/deliver` **multipart**: `note` (5–3000) و`files[]` (حتى 10، النوع والحجم من `freelance.upload_max_kb`). كل تسليم نسخة جديدة مرقّمة؛ الأخطاء: `delivery_too_many_files`.
- الطرفين (برفيكس كل تطبيق): `GET projects/{uuid}/milestones/{milestone}/deliveries` (الأحدث أولًا: `number, note, delivered_at, revision_reason, files[{id,name,mime,size,is_image,path}]`) و`GET …/deliveries/{delivery}/files/{media}` (الصورة inline وغيرها تحميل؛ `nosniff` و`no-store`؛ غير الطرفين 404).
- طلب التعديل (`…/revision {reason}`) بيكتب السبب على النسخة اللي العميل كان بيراجعها.
- العميل: `PUT …/milestones/{milestone}/checklist {items:[…]}` (حتى 10 بنود × 200 حرف) لحد ما الشغل يبدأ (`checklist_locked` 409)، و`POST …/checklist/{item}/check|uncheck` وقت المراجعة فقط (`checklist_not_reviewing` 409). التعليم ما بيشترطش للاعتماد، وبيتصفّر بعد جولة التعديل.
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
