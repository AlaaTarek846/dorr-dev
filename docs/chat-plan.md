# Chat Plan

> **الحالة (2026-09-29): معتمدة وبدأ التنفيذ.** القرارات في [§ 12](#12-needs-decision--أسئلة-لازم-تتجاوب-قبل-التنفيذ) اتقفلت، والباقي ماشي بالافتراضات المكتوبة هناك.
> **✅ الباك اند خلص** (`Modules/Chat`، 23 اختبار في `tests/Feature/ChatTest.php`): المراحل 1–9 و11 في [§ 13](#13-المراحل-المقترحة-بعد-قفل--12) + مشاركة المحفظة + إعدادات الأدمن. الـ Endpoints في [modules/chat/API.md](modules/chat/API.md).
> **✅ الموبايل خلص (المرحلة 12):** كل الشاشات بالديزاين والأنيميشن اللي في § 11، ومعاها الريل تايم (Pusher) والمكالمات (LiveKit) والرسايل الصوتية.
> **✅ الـ Stories خلصت (المرحلة 10):** باك وموبايل. الجمهور بيتحفظ لكل حالة وقت نشرها، والمشاهدات بتحترم علامات القراءة، والرد بيوصل في الشات.
> **⏳ لسه:** ثيمات وبلاغات الأدمن، والويب (المرحلة 13)، وفتح المحادثة من الـ Push على أندرويد.

**الأقسام:** 1 نظرة عامة على شات LeeTaxi · 2 الجداول · 3 **كل الفيتشرز (المستخدم)** · 4 شات السواق · 5 لوحة الأدمن · 6 الريل تايم والإشعارات · 7 🔴 مشاكل وثغرات في LeeTaxi (منتقلهاش) · 8 حاجات في واتساب مش في LeeTaxi · 9 مكان الموديول في Dorr · 10 الهيكل المقترح · 11 🎨 متطلبات ديزاين الموبايل · 12 NEEDS-DECISION · 13 المراحل

آخر تحديث: 2026-09-27
المصدر: `C:\laragon\www\LeeTaxi\Modules\Chat` (قراءة فقط، مفيش أي تعديل عليه).

---

## 1. نظرة عامة على الشات في LeeTaxi

- موديول مستقل `Modules/Chat` (nwidart)، كل الجداول بـ prefix `cht_`.
- **فيه نوعين محادثة** (`ChatTypeEnum`): `Single` (اتنين) و`Group` (جروب).
- المحادثة الفردية = صف في `cht_chat_channels` فيه طرفين polymorphic (`user1_*` / `user2_*`)، يعني ممكن تبقى **عميل ↔ عميل** أو **عميل ↔ سواق**.
- الجروب = صف channel بـ `type=Group` + صف في `cht_chat_groups` (الاسم/الوصف/الصورة) + أعضاء في `cht_chat_channel_members`.
- كل إعدادات المستخدم على المحادثة (كتم، إخفاء، حماية، ثيم، تثبيت، رسائل مختفية، حذف) **بتتخزن per-user** في جداول منفصلة، فكل طرف يشوف المحادثة بطريقته.
- الـ Routes: `user/chats/*` (guard `user_api`)، `driver/*` (guard `driver_api`)، `dashboard/*` (guard `admin_api`).
- الصلاحية على المحادثة عن طريق `Gate::define('read-channel-messages')`، ويشيّك إن المستخدم طرف أو عضو في الـ channel.
- الكود كله في الـ Controllers تقريباً. `ChtChatService` صغير (68 سطر): `createOrGetChannel`، `markMessagesAsReaded`، `getDisappearingDateForMessageIfExists`، `addMemberToGroupValidation`.

---

## 2. الجداول (35 migration)

| الجدول | الغرض | أهم الأعمدة |
|---|---|---|
| `cht_chat_channels` | المحادثة | `user1_type/id`, `user2_type/id` (nullable للجروب), `type` (Single/Group), `status` (Pending/Accept/Reject), softDeletes |
| `cht_chat_messages` | الرسايل | `sender_*`, `receiver_*` (nullable للجروب), `message`, `chat_channel_id`, `type` (Contact/Geo/Media), `amplitude` (موجة الصوت), `duration`, `read_at`, `has_link`, `disappearing_date`, `is_pin`, `expire_pin_date`, softDeletes |
| `cht_chat_groups` | بيانات الجروب | `name`, `description`, `image`, `chat_channel_id` |
| `cht_chat_channel_members` | أعضاء الجروب | `user_id`, `permission` (member/admin/super admin), `is_kicked`, softDeletes |
| `cht_channel_group_settings` | صلاحيات الجروب | `update_setting_group`, `send_message`, `add_members`, `accept_members` |
| `cht_readed_messages` | مين قرأ رسالة الجروب | `user_id`, `chat_message_id` |
| `cht_message_mentions` | المنشن (@) | `user_id`, `chat_message_id` |
| `cht_message_reacts` | الريأكشن | `user_id`, `chat_message_id`, `react` (إيموجي، واحد لكل مستخدم) |
| `cht_user_star_messages` | الرسايل المميزة بنجمة | `user_id`, `message_id`, `cht_channel_id` |
| `cht_user_deleted_messages` | "حذف عندي" | `user_id`, `message_id` |
| `cht_user_deleted_channels` | حذف المحادثة عندي | `user_id`, `chat_channel_id` |
| `cht_user_pin_channels` | تثبيت المحادثة فوق | `user_id`, `chat_channel_id` |
| `cht_user_channels_disappearing_messages` | الرسايل المختفية | `user_id`, `chat_channel_id`, `number_of_hours` |
| `cht_user_chat_settings` | إعدادات المستخدم على محادثة | `mute_notifications`, `expire_mute_notifications`, `is_protection`, `download_media`, `hide_chat`, `receive_notifications`, `notifications_on_lock_screen`, `show_message_contents` |
| `cht_general_settings` | إعدادات الخصوصية العامة للمستخدم | `hide_visibility`, `hide_message_seen`, `who_can_chat_with_me`, `who_can_call_me`, `who_can_add_me_to_group`, `download_media_to_gallery`, `disable_capturing_screenshot_from_chat`, 3 إعدادات للـ Story, `chat_lock`, `activate_protection`, `receive_notifications_from_hidden_chat`, `show_notifications_on_the_lock_screen`, `hide_message_contents` |
| `cht_user_societies` | فولدرات/تصنيفات المحادثات (زي Lists في واتساب) | `user_id`, `name` |
| `cht_user_chats_societies` | ربط محادثة بفولدر | `chat_channel_id`, `society_id`, `user_id` |
| `cht_themes` | ثيمات الشات (الأدمن بيضيفها) | `image` (خلفية), `sender_color`, `receiver_color`, `status`, `is_default` |
| `cht_chat_channel_themes` | الثيم المختار لمحادثة | `user_id`, `chat_channel_id`, `theme_id` أو ألوان/صورة custom |
| `cht_reports_types` | أنواع البلاغات (مترجمة) | `status` + translations |
| `cht_reports` | بلاغ على محادثة | `user_id`, `reports_type_id`, `chat_channel_id`, `report_details` |
| `cht_report_users` | المستخدمين المُبلَّغ عنهم | `report_id`, `user_id` |
| `cht_report_messages` | آخر 30 رسالة مرفقة بالبلاغ | `report_id`, `chat_message_id` |
| `cht_user_contact_favourites` | جهات الاتصال المفضلة | `user_id`, `contact_id` |
| جداول من موديول User | `us_contacts` (جهات الاتصال: `name`, `phone`, `image`, `contact_user_id`) و`us_blocked_users` (`user_id`, `blocked_user_id`) | |
| الميديا | جدول media عام (`saveFiles()`) مربوط polymorphic بالرسالة | `url`, `mime_type` |

---

## 3. كل الفيتشرز في LeeTaxi (المستخدم) — `user/chats/*`

### 3.1 قائمة المحادثات
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| قائمة المحادثات | `GET chat-channels` | paginate 15، الترتيب: **المثبتة أولاً** ثم وقت آخر رسالة. بتخفي المحذوفة عندي والمخفية. الفردية بتظهر بس لو فيها رسايل (الجروب بيظهر دايماً). الطلبات `Pending` بتظهر للي بدأها بس |
| بحث | `?search=` | في اسم/تليفون/إيميل الطرف التاني، نص الرسايل، اسم/وصف الجروب |
| فلترة بالفولدر | `?society_id=` | عرض محادثات فولدر معين |
| بيانات كل محادثة | `UserChatChannelResource` | الطرف التاني (**بالاسم المتسجل عندي في الكونتاكتس** لو موجود)، آخر رسالة، عدد غير المقروء، `has_mention` (فيه منشن ليا مش مقروء)، `is_pinned`, `is_admin`, `closed_chat` (لو حد من الطرفين اتحذف/اتحظر)، الثيم |

### 3.2 طلبات المراسلة (Message Requests)
أول رسالة من حد لحد تاني بتعمل محادثة `Pending`، والمستقبل يشوفها في صندوق منفصل ويقرر.
| الفيتشر | Endpoint |
|---|---|
| قائمة الطلبات | `GET messaging-requests` |
| عدد الطلبات (badge) | `GET number-of-messaging-requests` |
| قبول | `POST accept-messaging-request/{channel}` |
| رفض | `POST reject-messaging-request/{channel}` |

### 3.3 الرسايل
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| جلب الرسايل | `GET chat-messages/{channel}` | paginate 50 (الأحدث أولاً ثم معكوسة)، **بتعلّم الرسايل مقروءة تلقائي**، بتستبعد "المحذوفة عندي" |
| إرسال رسالة | `POST send-message` | نص و/أو ميديا متعددة (15MB للملف). الأنواع: صور، فيديو، **صوت (voice note) مع `amplitude` للموجة و`duration`**، مستندات (pdf/office/txt/zip/rar)، **موقع `Geo`**، **جهة اتصال `Contact`**. `mention_ids` للمنشن في الجروب. بيكشف اللينكات تلقائي (`has_link`). لو الرسايل المختفية مفعلة بيحط `disappearing_date` |
| حذف رسالة | `POST delete-message` | `delete_form_me` (عندي بس) أو `delete_ever_one` (للكل: صاحب الرسالة، أو أدمن الجروب). الرسالة المحذوفة للكل بتظهر "تم حذف هذه الرسالة" |
| الرسايل المختفية | `POST disappearing-channel-messages/{channel}` | toggle، بـ `number_of_hours` (الافتراضي 24). الرسالة بعد الوقت ده بتظهر "اختفت الرسالة" ومن غير ميديا |
| ريأكشن | `POST add-react-on-message/{msg}` / `remove-react-from-message/{msg}` | إيموجي واحد لكل مستخدم على الرسالة (يتغير) |
| مين عمل ريأكشن | `GET get-reacts-on-message/{msg}` | مجمّعة حسب الإيموجي + العدد + المستخدمين |
| مين شاف الرسالة (جروب) | `GET get-viewers-message/{msg}` | Message Info |
| تثبيت رسالة | `POST add-or-remove-pin/{msg}` | بمدة بالأيام (`duration`)، بتنتهي تلقائي |
| الرسايل المثبتة | `GET get-pin-messages/{channel}` | |
| نجمة (Star) | `POST star-message/{msg}` | toggle |
| الرسايل المميزة | `GET chat-star-messages/{channel}` + `GET chat-all-star-messages` | في محادثة، أو كل المحادثات |

### 3.4 معرض المحادثة (Media / Links / Docs / Locations)
| `GET media-messages/{channel}` | صور + فيديو + صوت |
|---|---|
| `GET document-messages/{channel}` | المستندات (`application/*`) |
| `GET link-messages/{channel}` | الرسايل اللي فيها لينكات |
| `GET location-messages/{channel}` | المواقع |

### 3.5 إعدادات المحادثة (per-user)
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| تثبيت المحادثة فوق | `POST chat-pin/{channel}` | toggle |
| حذف محادثة (أو أكتر) | `POST delete-channel` | بيخفيها عندي ويعلّم كل رسايلها محذوفة عندي. **لو جت رسالة جديدة المحادثة بترجع تظهر** |
| تعليم كغير مقروءة | `POST mark-channel-as-unread/{channel}` | (للجروب بس) |
| جلب الإعدادات | `GET get-setting-channel/{channel}` | |
| المحادثة المحمية | `POST update-protection-chat/{channel}` | `is_protection` (قفل ببصمة/رمز على الموبايل) |
| كتم الإشعارات | `GET mute-notifications-enum` + `POST update-mute-notifications/{channel}` | 8 ساعات / 24 ساعة / أسبوع / شهر / دائم / إلغاء الكتم، بتاريخ انتهاء |
| إخفاء المحادثة | `POST update-hide-chat/{channel}` | + استقبال إشعارات من المخفية، إظهار الإشعار على شاشة القفل، إخفاء محتوى الرسالة في الإشعار |
| ثيم المحادثة | `GET get-channel-themes/{channel}` + `POST change-channel-theme/{channel}` | اختيار ثيم من الأدمن، أو ألوان فقاعات custom + صورة خلفية |

### 3.6 الجروبات
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| إنشاء جروب | `POST create-channel-group` | اسم (3+)، وصف، صورة، أعضاء من الكونتاكتس، رسايل مختفية اختياري. المنشئ = `super admin` |
| إنشاء جروب مع شخص واحد | `POST create-channel-group-user` | من شاشة بروفايل حد |
| جروباتي | `GET get-channel-group-user` | (مع هل شخص معين عضو فيها — لشاشة "أضف لجروب") |
| إضافة أعضاء | `POST channel/add-members-group` / `add-user-member-group/{channel}` | **حد أقصى 1000 عضو** |
| الأعضاء | `GET channel/group-members/{channel}` | |
| طرد عضو | `POST channel/delete-member/{channel}/{user}` | أدمن بس، مينفعش يطرد الـ super admin |
| الأدمنز | `GET get-group-admins/{channel}` | |
| ترقية لأدمن / إلغاء | `POST make-member-as-admin/…` / `remove-admin/…` | |
| تعديل الجروب | `POST update-channel-group/{channel}` | اسم/وصف/صورة (المالك بس) |
| مغادرة | `POST member-leave-group/{channel}` | لو الـ super admin خرج، **أول عضو ياخد الملكية**، ولو مفيش أعضاء الجروب يتحذف |
| حذف الجروب | `POST cancel-group/{channel}` | أدمن |
| صلاحيات الجروب | `GET get-channel-group-setting/{channel}` + `POST update-channel-group-setting/{id}` | مين يعدّل الإعدادات، مين يبعت رسايل (Announcement group)، مين يضيف أعضاء، موافقة على الأعضاء |
| رسالة جماعية (Broadcast) | `POST send-message-to-group-of-contacts` | نفس الرسالة تتبعت لكل واحد في محادثة فردية منفصلة |

### 3.7 جهات الاتصال
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| الكونتاكتس | `GET get-registered-contacts` | بيعمل مطابقة تلقائي بين تليفون الكونتاكت و(كود الدولة + تليفون) المستخدمين المسجلين |
| إضافة كونتاكت | `POST add-new-registered-contact` | اسم + تليفون + صورة |
| بدء محادثة مع كونتاكت | `POST start-chat-with-contact` | لو مش مسجل: "جهة الاتصال دي مش مسجلة" |
| بدء محادثة بـ QR | `POST scan-qr-code/{user}` | |
| المفضلة | `GET get-favorite-contacts` / `get-not-favorite-contacts` + `POST update-favorite-contacts` | |

### 3.8 الفولدرات (Societies)
`GET user-societies` · `POST create-society` (**حد أقصى 10**، اسم unique) · `POST update-society/{id}` · `POST delete-society/{id}` · `POST add-channel-to-society/{id}`

### 3.9 الخصوصية والحظر
| الفيتشر | Endpoint | التفاصيل |
|---|---|---|
| الإعدادات العامة | `GET get-general-setting` + `POST update-general-setting/{id}` | إخفاء الظهور (Last seen)، إخفاء علامة القراءة، مين يراسلني، مين يتصل بيا، مين يضيفني لجروب (الكل / جهات اتصالي / لا أحد)، تنزيل الميديا، منع السكرين شوت، إعدادات الـ Story، قفل الشات |
| الحظر | `GET get-blocked-users` · `POST block-users` · `POST unblock-user/{user}` | |
| الإبلاغ | `GET get-report-types` + `POST add-report/{channel}` | نوع + تفاصيل + المستخدمين المُبلَّغ عنهم، وبيرفق **آخر 30 رسالة** تلقائي كدليل |

---

## 4. شات السواق — `driver/*`
نسخة مبسطة: `chat-channels` (قائمة + بحث)، `chat-messages/{channel}` (paginate 30 + قراءة تلقائية)، `send-message/{channel}` (نص + ميديا). مفيش جروبات ولا إعدادات. ده شات **العميل ↔ السواق** المرتبط بالرحلة.

## 5. لوحة الأدمن — `dashboard/*`
| الشاشة | الصلاحيات |
|---|---|
| ثيمات الشات CRUD (`admin-chat-themes`) — صورة، لون المرسل، لون المستقبل، مفعّل، افتراضي (واحد بس) | `chat theme read/create/edit/delete` |
| أنواع البلاغات CRUD مترجم (`admin-reports-types`) + dropdown | `chat reports type read/create/edit/delete` |
| البلاغات (`admin-reports`) عرض + تفاصيل | `chat reports read/edit` |

## 6. الريل تايم والإشعارات
- `App\Notifications\MessageNotification` — `ShouldBroadcast`، via `broadcast` بس، event اسمه `message.created`، على قناة المستقبل الخاصة `Modules.User.Models.User.{id}` (Pusher).
- **بيتبعت للمستقبل في الفردي بس** (في الجروب `receiver = null` فمحدش بيستقبل ريل تايم!).
- مفيش Push notification (FCM/OneSignal) للموبايل وهو مقفول، ومفيش تطبيق فعلي للكتم على الإشعارات.

---

## 7. 🔴 مشاكل وثغرات في LeeTaxi (مش هننقلها — هنصلحها في التصميم)

| # | المشكلة | المكان |
|---|---|---|
| 1 | `whereIn($request->messages)` ناقصه اسم العمود → حذف الرسايل مكسور | `ChtUserMessagesController@deleteMessage` |
| 2 | `return` جوه الـ loop → بيحذف أول رسالة بس من المختارة | نفس الدالة |
| 3 | الجروب مالهوش ريل تايم (receiver = null) | `sendMessage` |
| 4 | `markChannelAsUnRead` بياخد آخر قراءة **في السيستم كله** مش في المحادثة دي | `ChtChatChannelsSettingController` |
| 5 | `updateChannelGroupSetting` بيشيّك `user_id` مش موجود في الجدول → دايماً 403، **وصلاحيات الجروب مش متطبقة أصلاً** على الإرسال/الإضافة | `ChtChannelGroupSettingController` |
| 6 | `getChannelGroupSetting` و`getMessageStar` من غير Gate | |
| 7 | أي عضو يقدر يثبّت رسالة، وأي عضو يقدر يضيف أعضاء (مفيش تشييك أدمن) | `addOrRemovePin`, `addMembersGroup` |
| 8 | الخصوصية (`who_can_chat_with_me`, `who_can_add_me_to_group`, `hide_message_seen`) **متخزنة بس ومش متطبقة** | |
| 9 | الحظر مش متطبق على الإرسال — المحظور يقدر يبعت عادي | |
| 10 | `changeChannelTheme` بيعمل `updateOrCreate` بالـ channel بس → ثيم طرف بيمسح ثيم الطرف التاني | `ChtThemeSettingController` |
| 11 | كتم الإشعارات: `$hours[0]` بعد `array_filter` → المدة بتضيع لأي اختيار غير الأول. وقيمة `'Moth'` غلط إملائي | `updateMuteNotifications` |
| 12 | الرسايل المختفية مش بتتمسح فعلياً (بتتخفي في الـ Resource بس) ومفيش Job للتنظيف | |
| 13 | N+1 تقيل: كل Resource بيعمل 5–8 queries (آخر رسالة، غير المقروء، الثيم، star…) | `UserChatChannelResource`, `ChtUserChatMessageResource` |
| 14 | Admin `update` للبلاغات بيعدّل `ReportsType` مش `Report` | `ChtAdminReportsController` |
| 15 | دوال Hidden Chat (`getHiddenChannels`, `hiddenChannel`…) كود ميت مالهوش routes | `ChtGeneralSettingController` |
| 16 | إعدادات Story ومكالمات من غير أي فيتشر Story/Calls | `cht_general_settings` |
| 17 | `read_at` في الفردي بس، مفيش "اتوصلت" (✓✓ رمادي) | |
| 18 | الـ Routes كلها POST للتعديل/الحذف ومش REST، ومفيش API Resources موحّدة | |

---

## 8. حاجات في واتساب مش موجودة في LeeTaxi (مقترح نضيفها)

| الفيتشر | الأولوية المقترحة |
|---|---|
| **الرد على رسالة (Reply/Quote)** + swipe to reply | 🔴 أساسي |
| **حالة الرسالة: اتبعتت ✓ / اتوصلت ✓✓ / اتقرت ✓✓ أزرق** | 🔴 أساسي |
| **بيكتب الآن… (Typing)** و**بيسجّل صوت…** | 🔴 أساسي |
| **متصل الآن / آخر ظهور** (مع احترام الخصوصية) | 🔴 أساسي |
| Push notifications للموبايل (مع الكتم والمحتوى المخفي) | 🔴 أساسي |
| تعديل رسالة (خلال 15 دقيقة) + "معدّلة" | 🟡 |
| إعادة توجيه (Forward) لأكتر من محادثة + "معاد توجيهها" | 🟡 |
| بحث جوه المحادثة + القفز للرسالة | 🟡 |
| أرشفة المحادثات | 🟡 |
| معاينة اللينكات (Link preview) | 🟡 |
| لينك دعوة للجروب + QR | 🟡 |
| الردود المتعددة: تحديد رسايل كتير (حذف/توجيه/نجمة) | 🟡 |
| مسودة الرسالة لكل محادثة (على الموبايل) | 🟢 |
| الاستطلاعات (Polls) | 🟢 |
| صور "مشاهدة مرة واحدة" | 🟢 |
| **Stories / Status** | ✅ داخلة في النطاق (قرار 2026-09-27) — التصميم في [§ 10.1](#101-stories-الحالة) |
| **مكالمات صوت/فيديو** | ✅ داخلة في النطاق (قرار 2026-09-27) — التصميم في [§ 10.2](#102-المكالمات-صوت--فيديو) |
| تشفير End-to-End | ❌ مش مقترح دلوقتي (بيكسر البلاغات والبحث في السيرفر) |

---

## 9. مكان الموديول في Dorr

- **موديول مستقل `Modules/Chat`** بنفس نمط `Modules/Wallet`: `app/{Enums,Exceptions,Http/{Controllers,Requests,Resources},Models,Services,Support,Events,Jobs,Policies}`, `routes/{admin,customer,provider,mobile}.php`, `config/config.php`, `database/{migrations,seeders}`.
- الـ Pattern الإجباري: `Route → Controller → Service → Model` + Form Request + API Resource، والرد بـ `App\Support\Api\ApiResponse` (مش `responseJson`).
- الأطراف بـ alias زي `OwnerType` في المحفظة (`user` / `provider` / `admin`) — مش `morphMap` (نفس الدرس اللي اتعلمناه في المحفظة).
- **الموجود بالفعل في Dorr ونستخدمه:**
  - Pusher (`pusher/pusher-php-server` + `pusher-js`) + `config/broadcasting.php` + `BroadcastOnlyNotification` (للأحداث اللحظية) و`sendNotification()`.
  - `spatie/laravel-medialibrary` للميديا (بدل `saveFiles()`).
  - `spatie/laravel-permission` لصلاحيات الأدمن.
  - Locales `ar` / `en` في `lang/*` و`resources/js/locales/*.json`.
  - **OneSignal** للـ Push: `sendPushNotification()` في `app/Support/helpers.php` (بـ `include_player_ids`)، والموبايل بيبعت الـ player id للسيرفر بالفعل (`NotificationApi.kt`).
- **مش موجود في Dorr ومحتاجينه:** جدول جهات الاتصال (`us_contacts` في LeeTaxi)، جدول الحظر، و`broadcasting/auth` endpoint للـ guards بتاعة الموبايل (`user_api`).

### 9.1 الأطراف (قرار 2026-09-27)
- **النسخة الأولى:** مستخدم ↔ مستخدم (فردي + جروبات) زي واتساب.
- **بعدين:** مستخدم ↔ Provider، ومستخدم ↔ سائق (موديول السائقين لسه مش موجود).
- **عشان كده:** الطرف في كل الجداول polymorphic بـ alias (`user` / `provider` / `driver`) من أول يوم، فإضافة Provider أو سائق بعدين = تفعيل alias وguard وroutes، من غير migration ولا تغيير في منطق الشات.

### 9.2 الريل تايم والـ Push (قرار 2026-09-27 — على مستوى المشروع كله)
- **Push = OneSignal**، و**الريل تايم = Pusher** حالياً.
- **بعدين هننقل الريل تايم على سيرفرنا.** عشان النقل يبقى تغيير config بس:
  - الباك يبعت بـ Laravel Broadcasting العادي (`ShouldBroadcast`) ومفيش أي نداء مباشر لـ Pusher SDK في كود الشات.
  - الموبايل والويب يتكلموا **بروتوكول Pusher** (`pusher-java-client` / `pusher-js`) بـ host/port/key من الـ config، مش hardcoded. Soketi وLaravel Reverb الاتنين بيدعموا البروتوكول ده، فالنقل = تغيير `BROADCAST_CONNECTION` والـ host.
  - نقلل رسايل Pusher (عشان الحدود والتكلفة): typing بـ throttle (مرة كل 3 ثواني)، وclient events بدل ما تعدي على السيرفر.

---

## 10. الهيكل المقترح (تصميم أنضف من LeeTaxi)

الفكرة: **نجمّع الإعدادات per-user في جدول المشاركين** بدل 8 جداول منفصلة، ونوحّد الفردي والجروب.

| الجدول المقترح | بيحل محل (LeeTaxi) | ملاحظات |
|---|---|---|
| `chat_conversations` (`type`: direct/group, `status`: pending/accepted/rejected, `created_by`, `last_message_id`, `last_message_at`, `direct_key` unique للفردي) | `cht_chat_channels` | `last_message_*` denormalized → القائمة بـ query واحدة من غير GROUP BY |
| `chat_groups` (`name`, `description`, `avatar`, `invite_token`, صلاحيات: `only_admins_send`, `only_admins_edit_info`, `only_admins_add_members`, `approve_new_members`) | `cht_chat_groups` + `cht_channel_group_settings` | |
| `chat_participants` (`conversation_id`, `participant_type/id`, `role`: member/admin/owner, `joined_at`, `left_at`, `removed_by`, `last_read_message_id`, `last_delivered_message_id`, `unread_count`, `is_pinned`, `is_archived`, `is_hidden`, `is_locked`, `muted_until`, `cleared_at`, `deleted_at`, `disappearing_seconds`, `theme_id`, `custom_theme` json, `folder_ids`) | members + pin + deleted channels + disappearing + user_chat_settings + channel_themes | **الفردي كمان ليه صفين participants** → نفس الكود للنوعين |
| `chat_messages` (`conversation_id`, `sender_type/id`, `type`: text/image/video/audio/voice/document/location/contact/system, `body`, `meta` json (lat/lng، amplitude، duration، contact، link preview), `reply_to_id`, `forwarded_from_id`, `mentions` json, `edited_at`, `deleted_for_everyone_at`, `expires_at`, `client_uuid` unique للـ idempotency) | `cht_chat_messages` | `client_uuid` عشان الموبايل يعرض الرسالة فوراً (optimistic) من غير تكرار |
| `chat_message_receipts` (`message_id`, `participant_id`, `delivered_at`, `read_at`) — للجروب بس | `cht_readed_messages` | في الفردي كفاية `last_read/delivered_message_id` |
| `chat_message_reactions` | `cht_message_reacts` | unique (message, user) |
| `chat_message_user_states` (`is_starred`, `deleted_for_me`) | stars + deleted messages | |
| `chat_pinned_messages` (`pinned_by`, `expires_at`) | `is_pin` على الرسالة | |
| `chat_folders` + `chat_folder_conversations` | societies | |
| `chat_privacy_settings` (per-user) | `cht_general_settings` | بس الحاجات اللي هنطبقها فعلاً |
| `contacts`, `user_blocks` | `us_contacts`, `us_blocked_users` | |
| `chat_themes`, `chat_report_types` (+translations)، `chat_reports` (+users, +messages) | نفس الفكرة | |

**Services مقترحة:** `ConversationService` (إنشاء/جلب فردي، طلبات المراسلة)، `MessageService` (إرسال/تعديل/حذف/توجيه/ريأكشن/تثبيت)، `GroupService`، `ReceiptService` (delivered/read)، `PrivacyGuard` (بيطبق الحظر + مين يراسلني + مين يضيفني — **في مكان واحد**)، `ChatBroadcaster`، `ContactMatcher`.

**الريل تايم (Pusher) — أحداث مقترحة:**
| Event | القناة | 
|---|---|
| `message.sent` / `message.updated` / `message.deleted` / `reaction.changed` | private لكل مشارك (`chat.user.{id}`) |
| `receipt.updated` (delivered/read) | للمرسل |
| `typing` / `recording` | **client events** على `private-chat.conversation.{id}` (من غير ما تعدي على السيرفر) |
| `presence` (online/last seen) | presence channel |
| `conversation.updated` (اسم جروب، أعضاء، إعدادات) | لكل الأعضاء |

**Jobs:** `PurgeExpiredMessages` (الرسايل المختفية فعلاً)، `ExpirePinnedMessages`، `SendChatPush` (OneSignal عن طريق `sendPushNotification()`، مع مراعاة الكتم/المحادثة المخفية/إخفاء المحتوى، ومفيش push لو المستخدم online وفاتح المحادثة).

**جهات الاتصال (قرار 2026-09-27): الـ 3 طرق مع بعض**
1. **Sync من الموبايل:** بعد إذن جهات الاتصال، الموبايل يرفع الأرقام (normalized بصيغة E.164 على حسب دولة المستخدم) على دفعات، والسيرفر يرجّع مين منهم مسجل. نخزن الكونتاكتس في `contacts` (`owner`, `name`, `phone_e164`, `contact_user_id`). الـ sync incremental (المضاف/المعدّل/المحذوف بس)، وزر "تحديث" يدوي.
2. **بحث بالرقم:** `GET contacts/lookup?phone=` بيرجّع المستخدم لو موجود (مع احترام الخصوصية)، وrate limit عشان محدش يلف على الأرقام.
3. **QR:** كل مستخدم ليه QR (توكن موقّع مش الـ id الصريح، وممكن يتعمله reset)، والمسح بيفتح المحادثة أو يضيف الكونتاكت.

### 10.0 إعدادات الدردشة من الأدمن (قرار 2026-09-27)
الحدود **مش في الكود ولا في `.env`**. صف واحد في جدول `chat_settings` بيتعدّل من لوحة الأدمن (`GET/PUT /api/admin/v1/chat-settings`، صلاحية `chat-settings.view/update`)، وعليه cache بيتمسح مع كل تعديل. القيم الافتراضية:

| الإعداد | الافتراضي |
|---|---|
| `max_group_members` | 1024 |
| `max_file_size_mb` | 100 |
| `edit_window_minutes` (تعديل الرسالة) | 15 |
| `delete_for_everyone_window_minutes` | 2880 (يومين) |
| `deleted_message_retention_days` | 30 |
| `max_folders` | 10 |
| `max_pinned_messages` (في المحادثة) | 3 |
| `max_forward_targets` | 5 |
| `story_duration_hours` / `story_video_max_seconds` | 24 / 60 |
| `max_call_participants` | 8 |
| `stories_enabled` / `calls_enabled` | true / true |

### 10.0.1 مشاركة المحفظة جوه الشات (قرار 2026-09-27)
نوعين رسايل جداد بيربطوا الشات بموديول `Wallet`:
1. **`wallet_transfer` (إيصال تحويل):** بعد ما أحوّل لحد، أقدر أبعت "إيصال التحويل" في أي محادثة. العميل بيبعت `wallet_transaction_uuid` بس، **والسيرفر هو اللي بيبني الكارت** من الحركة نفسها: المبلغ، والعملة، والتاريخ، والرقم المرجعي، واسم المستلم مخفي جزئياً (`MaskedName`). وده بشرط إن الحركة `transfer_out` **من محفظتي أنا**، فمحدش يقدر يزوّر إيصال أو يبعت إيصال حد تاني. الكارت بيتعرض زي شاشة نجاح التحويل.
2. **`wallet_qr` (QR المحفظة):** أبعت الـ QR بتاع محفظتي (`WalletNumber::qrPayload()`) لحد في الشات، والسيرفر برضه اللي بيبنيه من محفظتي في الدولة الحالية. لما الطرف التاني يدوس "حوّل" بيفتح شاشة تأكيد التحويل (`wallet/transfers/lookup` بـ `mode=qr`)، **والرسالة نفسها عمرها ما بتحرّك فلوس**.
- الإتنين بيتخزنوا snapshot في `meta`، فلو حاجة اتغيرت بعدين الإيصال فاضل زي ما هو.

### 10.1 Stories (الحالة)
| الجدول | الأعمدة |
|---|---|
| `stories` | `owner_type/id`, `type` (text/image/video), `body`, `background` (لون/جراديانت للنص), `font`, `media` (medialibrary), `duration_seconds`, `audience` (contacts / contacts_except / only_share_with), `expires_at` (24 ساعة), `allow_reply` |
| `story_audience` | `story_id`, `user_id`, `mode` (except/only) — أو قائمة ثابتة per-user في إعدادات الخصوصية |
| `story_views` | `story_id`, `viewer_id`, `viewed_at` (مع احترام `hide_my_story_views`) |
| `story_reactions` | `story_id`, `user_id`, `react` |
| `story_mutes` | `user_id`, `muted_user_id` |

- الرد على Story = رسالة في المحادثة الفردية فيها `meta.story_id` (مع معاينة صغيرة للـ Story).
- الإعدادات اللي كانت في LeeTaxi بقت ليها معنى: منع حفظ/تصوير الـ Story (`FLAG_SECURE` على الموبايل)، وإخفاء إني شفت Stories الناس.
- Job `PurgeExpiredStories`، والميديا تتمسح بعد الانتهاء (إلا لو اتعمل لها Archive).
- **الموبايل:** شريط دواير فوق قائمة المحادثات بـ gradient ring للي مش متشاف، والعارض fullscreen بـ progress bars متحركة، tap يمين/شمال، ضغط مطوّل للإيقاف، سحب لتحت للإغلاق، سحب لفوق للرد، وانتقال cube بين الأشخاص.

### 10.2 المكالمات (صوت / فيديو)
- **مكالمة 1:1 وجروب** (صوت + فيديو).
- **الإشارة (signaling):** عن طريق الريل تايم (Pusher): `call.ringing` / `call.accepted` / `call.rejected` / `call.ended`، وPush من OneSignal لما التطبيق مقفول (**بأولوية عالية**، وعلى أندرويد شاشة مكالمة كاملة `ConnectionService`/full-screen intent).
- **الميديا نفسها** محتاجة WebRTC + سيرفر TURN، أو خدمة SFU. الخيارات في [§ 12 بند 11](#12-needs-decision--أسئلة-لازم-تتجاوب-قبل-التنفيذ).
- `chat_calls` (`conversation_id`, `type` audio/video, `initiator`, `status` ringing/ongoing/ended/missed/rejected, `started_at`, `ended_at`, `duration`) + `chat_call_participants` (`joined_at`, `left_at`). المكالمة بتظهر في المحادثة كرسالة `system` ("مكالمة فائتة")، وفيه تبويب "المكالمات" (Call log).
- الخصوصية `who_can_call_me` بقت ليها معنى، والحظر يمنع المكالمات.
- **الموبايل:** شاشة الرنين بأفاتار بيعمل pulse، والرد بالسحب، وإخفاء/إظهار أزرار التحكم بأنيميشن، وPiP لمكالمة الفيديو، والانتقال لوضع الصوت لو الكاميرا اتقفلت.

---

## 11. 🎨 متطلبات ديزاين الموبايل (Android — Jetpack Compose)

> **مطلب صريح من صاحب المشروع:** الديزاين يكون **ممتاز جداً، حديث، وفيه أنيميشن وحركات**. ده جزء من الـ Definition of Done، مش إضافة. الشاشات لازم تمشي على `ui/theme` (الألوان/الخط)، وتدعم **Dark mode** و**RTL** (عربي/إنجليزي).

### الشاشات
1. **قائمة المحادثات:** tabs (الكل / غير مقروء / جروبات / الفولدرات) بمؤشر بيتحرك، بحث بيتفتح من الـ AppBar بأنيميشن، صف المحادثة (أفاتار + نقطة Online + آخر رسالة + وقت + badge غير مقروء + أيقونة كتم/تثبيت/منشن)، **swipe** (أرشفة/تثبيت/كتم)، long-press لتحديد متعدد، بانر "طلبات المراسلة (3)"، FAB لمحادثة جديدة.
2. **شاشة المحادثة:** فقاعات بألوان الثيم وخلفيته، ذيل الفقاعة، تجميع الرسايل المتتالية، **فاصل تاريخ sticky**، علامات ✓/✓✓/✓✓ أزرق، "بيكتب…"، رسالة مثبتة فوق، زر "انزل لتحت" بعدّاد، وأنواع الرسايل كلها: صورة/ألبوم grid، فيديو بـ thumbnail، **voice note بموجة**، مستند بأيقونة وحجم، موقع بخريطة مصغرة، جهة اتصال، لينك بمعاينة، رد مقتبس، "معاد توجيهها".
3. **الكتابة:** حقل بيكبر مع النص، زر ميكروفون بيتحول لزر إرسال، قائمة مرفقات (كاميرا/معرض/مستند/موقع/جهة اتصال)، إيموجي، معاينة الرد فوق الحقل، اقتراحات المنشن `@`.
4. **معلومات المحادثة/الجروب:** هيدر كبير بيتقلص مع السكرول (collapsing)، الميديا/المستندات/اللينكات في tabs، الأعضاء والأدمنز، الإعدادات (كتم، ثيم، رسائل مختفية، قفل، إبلاغ، حظر).
5. إنشاء جروب (اختيار أعضاء بـ chips متحركة)، جهات الاتصال، الرسايل المميزة، اختيار الثيم مع **معاينة حية**، إعدادات الخصوصية، المحظورين، الفولدرات.
6. عارض ميديا fullscreen (zoom/pinch، سحب لتحت للإغلاق).

### الأنيميشن والحركات (إلزامي)
| المكان | الحركة |
|---|---|
| فتح المحادثة | **Shared element** للأفاتار والاسم من القائمة للهيدر |
| رسالة جديدة | الفقاعة بتدخل بـ slide + fade + scale خفيف (spring)، والمبعوتة بتطلع من مكان حقل الكتابة |
| الإرسال | زر الميك ↔ الإرسال بـ morph/rotate، والرسالة بتظهر فوراً (optimistic) بساعة ⏱ وبعدين ✓ |
| علامات القراءة | ✓ → ✓✓ → أزرق بأنيميشن لون |
| Swipe to reply | الفقاعة بتتسحب وأيقونة رد بتكبر + **haptic** عند العتبة |
| Long press | الخلفية بتتعمل **blur**، والرسالة بتكبر، وشريط الريأكشن بيطلع بـ spring (الإيموجي واحد ورا التاني staggered) + قائمة الأوامر |
| الريأكشن | الإيموجي "بينط" على الفقاعة (bounce)، والعدّاد بيتغير بـ AnimatedContent |
| Typing | 3 نقط بتتحرك (wave) |
| Voice note | اضغط وسجّل: موجة حية، **اسحب لليسار للإلغاء** (سلة بتاكل التسجيل)، **اسحب لفوق للقفل**؛ التشغيل بتقدم على الموجة |
| القائمة | Skeleton **shimmer** وقت التحميل، items بتدخل staggered، animateItem للترتيب لما رسالة جديدة ترفع المحادثة لفوق |
| Badge غير المقروء | pop/scale لما الرقم يتغير |
| الحالات الفاضية | رسومات أنيميشن (Lottie) لـ "مفيش محادثات/نتائج" |
| الانتقالات بين الشاشات | slide + fade متسقة، والهيدر collapsing في شاشة المعلومات |
| Online dot | pulse خفيف |
| Pull to refresh / "انزل لتحت" | FAB بيظهر ويختفي بـ scale |

> **مكتبات محتاجة إضافة (NEEDS-DECISION على المكتبات):** `pusher-java-client` (ريل تايم)، Lottie Compose، Media3/ExoPlayer (فيديو/صوت)، CameraX أو Photo Picker، Coil (موجود بالفعل)، Firebase Messaging/OneSignal (Push)، Paging 3، Room (كاش أوفلاين للمحادثات والرسايل).

### الويب (Vue — `/user` و`/provider`)
نفس الفيتشرز الأساسية بتصميم شبه واتساب ويب (قائمة يسار + محادثة يمين)، بـ `transition-group` للفقاعات، و`pusher-js`. شاشات الأدمن بـ PrimeVue + `crudStructure.js`: الثيمات، أنواع البلاغات، البلاغات (مع عرض الرسايل المرفقة وإجراء: حظر/تحذير/إغلاق).

---

## 12. NEEDS-DECISION — أسئلة لازم تتجاوب قبل التنفيذ

**اتقفلت (2026-09-27):**
1. [x] **الأطراف:** مستخدم ↔ مستخدم دلوقتي. مستخدم ↔ Provider ومستخدم ↔ سائق بعدين، والتصميم polymorphic من الأول ([§ 9.1](#91-الأطراف-قرار-2026-09-27)).
2. [x] **جهات الاتصال:** sync من الموبايل + بحث بالرقم + QR، الـ 3 مع بعض.
4. [x] **Stories والمكالمات:** داخلين في النطاق ([§ 10.1](#101-stories-الحالة) و[§ 10.2](#102-المكالمات-صوت--فيديو)).
5. [x] **Push:** OneSignal (المستخدم في المشروع كله).
6. [x] **الريل تايم:** Pusher حالياً، وبعدين سيرفرنا، والكود مكتوب بحيث النقل يبقى config بس ([§ 9.2](#92-الريل-تايم-والـ-push-قرار-2026-09-27--على-مستوى-المشروع-كله)).

**لسه مفتوحة. ليها افتراض مقترح، ولو مفيش اعتراض هنمشي عليه:**
3. [ ] **طلبات المراسلة:** *المقترح:* أي حد مش في جهات اتصالي رسالته الأولى تروح "طلبات المراسلة" (Pending)، ولحد ما أقبل مش هيعرف إني قريتها.
7. [ ] **الحدود:** *المقترح:* الجروب 1024 عضو، والفولدرات 10، والملف 100MB (الفيديو يتضغط على الموبايل قبل الرفع)، وتعديل الرسالة خلال 15 دقيقة. وكلها في `Modules/Chat/config/config.php` مش hardcoded.
8. [ ] **الرسايل المختفية:** *المقترح:* مدد ثابتة (24 ساعة / 7 أيام / 90 يوم)، ودي إعداد على المحادثة يشوفه الطرفين (زي واتساب، مش per-user زي LeeTaxi)، وتتمسح من السيرفر فعلاً.
9. [ ] **الحذف للكل:** *المقترح:* soft delete، فالمحتوى بيختفي عن الكل بس بيفضل موجود 30 يوم عشان البلاغات، وبعدين يتمسح. **ده لازم يتكتب في سياسة الخصوصية.**
10. [ ] **فلوس جوه الشات** (رسالة `payment` بتستخدم `TransferService`): *المقترح:* مش في النسخة الأولى.
7b. [x] **الحدود = إعدادات دردشة من الأدمن** ([§ 10.0](#100-إعدادات-الدردشة-من-الأدمن-قرار-2026-09-27)).
10b. [x] **مشاركة إيصال التحويل وQR المحفظة جوه الشات** ([§ 10.0.1](#1001-مشاركة-المحفظة-جوه-الشات-قرار-2026-09-27)).
11. [x] **المكالمات = LiveKit (قرار 2026-09-27).** السيرفر بيصدر access token (JWT HS256) لكل مشارك وغرفة، و`LIVEKIT_URL`/`LIVEKIT_API_KEY`/`LIVEKIT_API_SECRET` في `.env` (Cloud دلوقتي، وself-host بعدين من غير تغيير كود). الخيارات اللي كانت مطروحة:
    - (أ) **WebRTC مباشر + Pusher للـ signaling + سيرفر TURN (coturn) عندنا:** مجاني، بس جروب الفيديو صعب (mesh، حد أقصى 4–6 أشخاص تقريباً)، والجودة مسؤوليتنا.
    - (ب) **LiveKit (open source):** نبدأ بـ LiveKit Cloud، ونقدر ننقله على سيرفرنا بعدين، بنفس فكرة Pusher. بيدعم الجروب والـ SDK بتاعه للأندرويد والويب كويس. **← المقترح، لأنه ماشي مع خطة "خدمة مدفوعة دلوقتي وسيرفرنا بعدين".**
    - (ج) Agora / ZegoCloud: أسهل حاجة، بس مدفوع بالدقيقة ومفيش نقل لسيرفرنا.
12. [ ] **مدة الـ Story:** *المقترح:* 24 ساعة، والفيديو لحد 60 ثانية (بيتقسم لو أطول).

---

## 13. المراحل المقترحة (بعد قفل § 12)

| المرحلة | المحتوى | Deliverable |
|---|---|---|
| 0 | قفل القرارات | تحديث الملف ده |
| 1 | سقالة `Modules/Chat` + config + participant aliases + `contacts` + `user_blocks` + `chat_privacy_settings` | موديول مسجّل واختبارات |
| 2 | نواة: conversations/participants/messages + `ConversationService`/`MessageService` + `PrivacyGuard` + إرسال نص + قائمة + جلب رسايل + read/delivered | API + اختبارات Feature |
| 3 | الريل تايم: Pusher events + `broadcasting/auth` للموبايل + typing/presence | اختبار بـ `Event::fake` + تجربة فعلية |
| 4 | الميديا (medialibrary): صور/فيديو/صوت بموجة/مستند/موقع/كونتاكت + المعرض | |
| 5 | reply / forward / edit / delete / reactions / star / pin / بحث | |
| 6 | الجروبات + الأدوار + الصلاحيات **متطبقة فعلاً** + لينك دعوة | |
| 7 | إعدادات المحادثة: كتم/أرشفة/إخفاء/قفل/ثيم/رسائل مختفية + Jobs + الفولدرات | |
| 8 | البلاغات + الأدمن (ثيمات، أنواع بلاغات، بلاغات) + صلاحيات Spatie | |
| 9 | Push بـ OneSignal (`SendChatPush`) | |
| 10 | **Stories** (§ 10.1) | |
| 11 | **المكالمات** (§ 10.2) بعد قفل بند 11 | |
| 12 | **الموبايل** (بالتوازي من المرحلة 3): الشاشات كلها + الأنيميشن في § 11 + كاش أوفلاين | |
| 13 | الويب (`/user`) | |
| لاحقاً | تفعيل الأطراف `provider` و`driver` (§ 9.1)، ونقل الريل تايم لسيرفرنا (§ 9.2) | |
| كل مرحلة | `composer test` + `npm run build` + تحديث `06-API-SPECIFICATION.md` / `05-DATA-MODEL.md` / `11-CHECKPOINT.md` | |
