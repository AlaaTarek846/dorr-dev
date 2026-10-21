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

- **القوائم:** `data` مصفوفة، و`pagination` فيها بيانات الصفحات.
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
| GET | `offers` | عروضي، الأحدث أول (مع `project{uuid,title}`) |
| GET | `offers/{uuid}/attachments/{id}` | ملف من عرضي |

**ترتيب الفحص:** حساب موثّق وبيستقبل شغل (`freelance_verification_required` 403 / `freelance_unavailable`) ← المشروع بيستقبل عروض (`freelance_project_offers_closed`) ← مفيش عرض قبل كده (`freelance_offer_already_sent` 409) ← المشروع مش مكتمل (`offers.max_per_project`، `freelance_project_offers_full`) ← الحصة الشهرية (`offers.free_monthly_quota` لو مفتاح `free_offer_quota` مفتوح، `freelance_offer_quota_exhausted` و`data.limit`). السعر حر بس مش أقل من حد ميزانية الدولة/التخصص (`freelance_offer_below_min`). العمولة والصافي بيتحسبوا وقت الإرسال ويتحفظوا مع العرض. العرض اللي بيكمّل العدد بيقفل المشروع (`close_reason = max_offers`). العميل بيتبلّغ (`freelance.offer.received`).

**العميل** (`user_api`): `GET mobile/v1/freelance/projects/{uuid}/offers` (العروض مع المحترف: الاسم والعنوان والصورة وموثّق ولا لأ — من غير صافي ربحه) و`GET …/offers/{offer}/attachments/{id}`. رد المشروع فيه `offers.count`.

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

- `GET offers?sort=newest|cheapest|fastest|completeness&status=all|shortlisted|…` — كل عرض فيه `freelancer{completeness, years_of_experience, portfolio_count, specialties, skills, matching_skills, project_skills_count, rating, reviews_count, completed_projects}` (التقييم والمنجز `null` لحد FL-701/703) و`can{shortlist, reject, choose}` و`client_cost{price_minor, client_fee_minor, total_minor, currency}` (رسوم العميل من السياسة لو مفتاح `client_fee` شغال).
- `GET offers/compare?ids=a,b[,c]` — من 2 لـ 3 عروض بترتيب الاختيار + `best{price, duration, completeness, skills}`. عدد تاني = `freelance_offer_compare_count` (422)؛ عرض مش في المشروع = 404.
- `GET offers/{offer}` — عرض واحد بنفس الشكل.
- `GET offers/{offer}/freelancer` — بروفايل المحترف للعميل: نبذة، خبرات، شهادات، معرض الأعمال (روابط صور موقّعة)، لغات، مدينة. **من غير تليفون ولا إيميل.**
- `POST offers/{offer}/choose` — transaction واحدة: العرض `accepted` (`accepted_at`)، الباقي `rejected` تلقائي (حدث فيه `auto: another_chosen`، والمحترف بيقرا «العميل اختار عرض تاني» في `status_reason`)، العروض بتتقفل (`close_reason = chosen`) والمشروع `awarded` (`awarded_at`، حدث `project.awarded`). اختيار تاني = `freelance_offer_already_chosen` (409)؛ مشروع مش منشور = `freelance_project_invalid_state`.
- حالة المشروع الجديدة `awarded`: `published → awarded → cancelled` (FL-401 هيضيف الاتفاق). المحترف لسه يقدر يفتح مشروع قدّم عليه بعد ما اتختار عرض.
