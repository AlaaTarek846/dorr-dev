# Project Checkpoint


**Last updated:** 2026-10-05  
**Purpose:** Quick orientation for developers and AI assistants.

---

## Current Phase

**Foundation + Admin / User / Provider platforms operational.**  
Three dashboard SPAs (Admin, User, Provider). Documentation system established. Public website and permission system not implemented.

---

## Completed Work

### Backend
- Laravel 12 monolith with 5 modules (Admin, User, AI, Provider, SMS)
- Shared catalog in `app/.../General/` (Country, Currency, Flag, Language, ServiceCategory, Faq, LegalPage, PlatformSetting)
- Sanctum auth with `admin_api`, `user_api`, and `provider_api` guards
- Standard API response envelope
- Translation-based catalog pattern
- AI gateway with multiple providers
- Provider profiles with service category linkage
- Provider dashboard API: `/api/provider/v1/*` (auth, registration, profile, password reset, `countries/dropdown`)
- Provider admin API: `/api/admin/v1/providers*` (CRUD, trash, status) via `Modules/Provider/routes/admin.php`
- Provider OAuth web routes + shared Google/Apple callback via `social_auth_panel` session
- `RedirectIfAuthenticated`: JSON 403 for authenticated guests on `api/provider/*`
- `SocialAuthService::authenticate(..., $allowRegistration)` — explicit registration flag for OAuth
- SMS module via `Modules/Sms/routes/admin.php`: `/api/admin/v1/sms-providers*` and `/api/admin/v1/sms-accounts*` (CRUD, status, single default, connection test, draft test, send test, balance)
- SMS provider registry with 3 adapters (twilio, sms_misr, four_jawaly); `SmsProvider` holds identity/status plus an optional encrypted per-provider `configuration` (seeds the account form, supports `test-draft`), while `SmsAccount` is the single source of truth for sending credentials (`encrypted:array` cast)
- `SmsException implements ApiRenderable` — service-layer business errors become standard API error envelopes without controller try/catch
- Country-driven E.164 normalisation (`PhoneNumberNormalizer`) using `Country::dial_code` / `phone_starts_with` / `phone_length`; no `libphonenumber` in the project
- See [docs/modules/sms/README.md](./modules/sms/README.md)
- FAQ catalog (`faqs` / `faq_translations`) and Legal Pages catalog (`legal_pages` / `legal_page_translations`, `type` = privacy | term; replaced the Privacy Policy catalog on 2026-09-29), each with optional `service_id` → `service_categories.id` (`nullOnDelete`), `status`, `sort_order`, soft deletes, and multilingual fields
- Admin APIs: `/api/admin/v1/faqs*` (`faqs.*` permissions) and `/api/admin/v1/legal-pages*` (`legal-page.*` permissions) — CRUD, trash, restore, force delete, status, bulk delete, dropdown
- `ResolvesTranslatableFields` concern; translation sync/response/formatting generalized so a catalog can expose more than one translatable field (single-field `name` catalogs unchanged)

### Frontend
- Admin SPA: full catalog CRUD, users, providers, AI settings, platform settings
- User SPA: auth flows, profile, AI chat, messages (web chat, `/user/messages`)
- **Provider SPA:** `/provider` — auth flows mirror User (login, register, verify, password, OAuth), dashboard, profile, service header/sidebar — **no AI chat**
- Entry: `resources/js/apps/provider/provider-app.js`; router `provider-index.js`; token `provider_token`
- i18n: Arabic + English (`provider_dashboard.*` for provider portal)
- Pinia stores, composable-based CRUD

### Documentation
- Full `docs/` structure (16 files + AI instructions)
- Module docs for Admin, User, AI, Provider, General

---

## Current Work

- **One payment screen, merchant portals, channel verification (2026-10-05, `docs/remaining_chat.md` ج):**
  - **Checkout:** `checkouts` in `Modules/Wallet`. Any module registers a purpose. Pay from the wallet with the PIN (spend_only first), or through a gateway: the gateway tops the wallet up, then pays the checkout once it confirms. Revenue is booked as `service_revenue`.
  - **Login country:** `users.logged_in_country_id` is set on every sign-in (by IP; SA when unknown). `CountryResolver` reads it before `country_id`.
  - **Chat:** categories with icons (admin), and packages per kind (`portal` / `channel_verification`), period and per-country price. Merchant portals have a name and description per language, AI translation, a page grouped by category and sorted by views, and one view per person per day. Channels gained a category, a ✔ (paid period or set by the admin), and a directory grouped by category and sorted by followers.
  - **Android:** `CheckoutLauncher`, `ui/screens/portals`, the portals circle on Home, and `ChatDirectoryUi.kt`.
  - **Migrations:** `2026_10_05_100000`–`100200`.
  - **Tests:** `ChatPortalsTest`, plus additions to `MobileAuthTest`.
- **Organising chats (2026-10-06):**
  - Broadcast lists (WhatsApp-style; only people who saved me receive them).
  - Privacy circles (spec 98–103): all five notification levels P0–P4, a stand-in name, hidden from the list and search, and a per-circle lock.
  - Threads (122).
  - Group decisions and their log (119–120).
  - Smart quiet with an end-of-quiet summary (115, `chat:quiet-digest` every minute).
  - Migrations `2026_10_06_100000`–`100400`. Tests: `ChatBroadcastTest`, `ChatCirclesTest`, `ChatThreadsDecisionsTest`.
  - Android: `ChatOrganizeUi.kt` (threads, decisions, circles, broadcasts) and the quiet sheet in `ChatShieldUi.kt`.
- **Partial chat items closed (2026-10-10):**
  - Items: 1 folder colours, 24 favourites folders, 6 text size and compact list, 13 photo quality by connection, 17/18 media by date and files by kind or size, 20 search in my voice transcripts, 21 search by kind, 25 a PIN per chat, 81 @usernames, 114 priority inbox, 123 "what I missed", 66 money in a chat, 153 decision room, 127 privacy center.
  - **Migration:** `2026_10_10_100000`.
  - **Test:** `ChatPartialItemsTest`.
  - **Android:** `network/ChatMoreApi.kt` and `ui/screens/chat/ChatPartialUi.kt`, plus hooks in the chat list, conversation, info page, media page, new chat and decisions.
- **DORR Calendar & DORR Today (2026-10-09, spec 201–207):**
  - **Sources:** one calendar of only the sources I keep on: appointments, occasions, my own dates, tasks and message reminders.
  - **Today:** a page in my order with what's next, overdue tasks and "around my interests".
  - **Views and search:** week and month views with filters, and one search that never touches messages.
  - **Smart reminders:** at my times, never twice, waiting for quiet hours (`chat:calendar-reminders`).
  - **Time zones:** an appointment keeps its real instant plus its zone, and is shown where I am.
  - **Admin switch:** `calendar_enabled`.
  - **Migration:** `2026_10_09_100000`. **Test:** `ChatCalendarTest`.
  - **Android:**
    - `ui/screens/calendar/` (`CalendarScreen`, `CalendarSheets`) in the app's Wa design.
    - A "my day" card on Home.
    - "Add to calendar" from chat dates.
    - The calendar push deep link.
- **DORR AI in the chat (2026-10-08, spec 31, 36–42, 46, 48, 49, 350–362):**
  - **Safety layer** in `Modules/AI/app/Safety`:
    - `RiskClassifier` classifies every request before the answer (religion, law, medicine, engineering, code; general or specific). It runs a separate classification call, plus fixed rules that decide when the call fails.
    - `SafetyPolicyEngine` sends the rules to the model as instructions. Afterwards it adds the approved referral and disclaimer itself, and removes "if needed"-style softeners.
    - It applies to the AI chat and to every free answer in the chat. Android shows an alert card (`SafetyCard`).
  - **Tools:** the assistant in a chat, proofread, "understand this message" (intent and tone), simplify, tasks from a message, a note into my notes, dates mentioned in a chat, what's important (one chat or across unread chats), related files, and "my day in chats".
    - Each tool runs on one tap and only produces suggestions.
    - Cross-chat reads never touch locked chats, locked or hidden circles, sensitive messages or view-once messages.
  - **My tasks:** the `chat_tasks` table, with a push at the due time (`chat:task-reminders`).
  - **Migrations:** `Modules/AI` `2026_10_08_100000` (`ai_messages.safety`), and Chat `2026_10_08_100000` (`chat_tasks`).
  - **Tests:** `AiSafetyTest` and `ChatAiToolsTest`.
  - **Android:**
    - `network/AiToolsApi.kt` and `ui/screens/chat/ChatAiToolsUi.kt`.
    - The proofread bar in the composer.
    - Message actions.
    - Entries in the AI menu and the chat list menu.
    - The `ChRoute.Tasks` page and the tasks deep link.
- **DORR Moments (2026-10-07, spec 157–168):**
  - **Engine and catalog:** occasions with gregorian, Umm al-Qura hijri, or manual dates, corrected by the admin per country and year.
    - About 70 occasions are seeded: religious, international, and national days for 22 countries, each with its own colours, emoji and animation.
    - Every look can be edited from the admin page `chat/moments`, which has a live preview.
  - **Preferences:** on/off, the level of effects, the country, and which occasions to show. Religion and nationality are never inferred: occasions that are off by default show only when picked.
  - **Center:** an animated banner on Home and the Moments page (on now, coming up, my own dates).
  - **Cards:**
    - The occasion's look, plus three AI greetings to start from.
    - My own voice and photos.
    - A sealed surprise until its time.
    - Scheduling in the recipient's time zone (`users.timezone` from `X-Timezone`).
    - A wallet gift with the PIN.
  - **Together and kept:** group cards everyone signs, and capsules that hold copies of the messages I choose to keep.
  - **Reminders for my own dates:** `chat:moment-reminders` runs every ten minutes. It pushes at 9 in the morning in the person's time zone, on the day and N days before. Tapping the push opens Moments.
  - **Migrations:** `2026_10_07_100000`–`100300`. The new permission is `chat-moments`.
  - **Tests:** `ChatMomentsTest`, `ChatMomentCardsTest`, `ChatMomentsTogetherTest`.
  - **Android:**
    - `ui/screens/moments/` (`MomentsScreen`, `MomentEffects`, `MomentCards`, `MomentsTogether`).
    - "Greeting card" in the attach menu.
    - The card bubble, with a countdown on surprises.
    - "Keep in a capsule" in the message menu.
  - AI about a chat: ask (spec 125, with references to the original messages; only picked messages go to the AI) and commitments (126, suggestions; a reminder only when confirmed). Android: `ChatAskUi.kt` and the ✨ in the conversation header. Tests in `ChatAiTest`.
- **Public stories on the home page (2026-10-05, ج.1):**
  - `chat_stories.is_public`, with the free count and an on/off switch in chat settings.
  - `GET stories/public`: mine, Dorr's, then people (unseen and most viewed first).
  - Phone numbers stay hidden until a message request is accepted (also `peer.phone` in a pending request I sent).
  - Dorr's own stories: `chat_dorr_stories`, managed from the admin.
  - Android: `HomeStories.kt` gives the home page its own ChatHost, so it reuses the chat's story viewer and composer (posting from home is public).
  - Migration `2026_10_05_100300`. Tests: `ChatPublicStoriesTest`.
- **Calls (2026-10-05):**
  - The speaker is routed through LiveKit's AudioSwitch.
  - Mute is verified after it's applied.
  - A failing camera no longer ends the call.
  - My camera shows while the call rings.
  - Ring, connect and alone timeouts, plus status polling.
  - Logout unregisters the phone's push id (`auth/logout {player_id}`), and a signed-out phone shows no pushes.

- **Chat phases 2 and 3 (2026-10-04, `docs/chat-tasks.md`):** group slow mode, banned words and invite links that expire; business tools (quick replies, opening hours, welcome / away auto-replies — `config('chat.business_participants')`); scheduled messages (`chat:send-scheduled` every minute — **needs the scheduler**); three notification privacy levels (`notification_privacy`); calls off per country (`calls_disabled_countries`); AI in the chat on a tap (translate, voice to text, summary, suggested replies — `Services\ChatAiService`, admin switch `ai_enabled`; the AI module gained speech to text for OpenAI, Groq and Google). Plus "make a sticker from my photo" (`stickers/mine`, ML Kit on Android) and the logo's navy `#001B53` / orange `#FA7552` as the app's colours. Migrations `2026_10_04_100000`–`100700`. Tests: `ChatModerationTest`, `ChatBusinessTest`, `ChatScheduledTest`, `ChatAiTest`, `ChatEssentialsTest`.

- **Interface translation management (2026-09-30, ADR-011):** Admin → Languages → Translations. New languages (e.g. `fr`) get backend / Vue / Android JSON groups via export → translate → import (draft) → publish. `ar`/`en` untouched and remain the bundled sources (`en` = base + fallback). Backend and Vue load published locales at runtime. Migration `2026_09_30_120000_create_translation_files_table`. Config `config/translations.php`.
- **Dynamic Android translations (2026-10-01, ADR-011):** the Android app lists languages from `GET /api/general/v1/translations/languages?platform=android` and downloads `GET /api/general/v1/translations/{code}/android` when a non-bundled language is chosen (ar/en stay bundled; `en` = fallback). One language file on the device (`filesDir/translations/{code}.json`), served by `ui/locale/DynamicResources.kt`; direction saved with it for offline cold starts; newer `android_version` downloaded on launch / when the language dialog opens; a language no longer offered falls back to `en`. The XML ZIP export still exists. Not covered: strings built outside Compose (`chat/CallNotifications.kt`, `chat/LiveLocationService.kt`) stay English for downloaded languages.
- Provider dashboard SPA **documented** (module docs + API spec aligned with code, 2026-09-20)
- Provider profile services dropdown + sidebar links from `services[]` / `category.module_name`
- **Mobile phone auth (Android):** `/api/mobile/v1/*` guard `user_api`; combined login/register by phone only; fixed demo OTP `123456` via `App\Traits\SendsPhoneOtp` (writes `verification_codes`); phone validated against `countries.phone_starts_with` / `phone_length`; `EnsurePhoneVerified` middleware blocks routes until `phone_verified_at` set; Android `LoginScreen`/`OtpScreen` wired to real APIs (`MobileAuthApi`)
- **Android first launch:** Splash (login logo) then a 3-step onboarding, only while `dorr_onboarding` / `onboarding_completed` is false. Skip and finish both set the flag. After that, Splash goes to Home when a session exists, otherwise Login. Logout does not reset the flag.
- **Android appearance (colors only):** after login, `GET/PUT mobile/v1/appearance`. Settings → Appearance: `dark_mode` (`system`/`light`/`dark`), primary + secondary hex (same value in light and dark custom tokens), reset via `uses_default_colors: true`. Cached in `dorr_appearance`. No snapshot → splash/login/OTP keep the built-in palette. Font is not sent.
- `service_categories`: `module_name`, `is_login_dashboard`, `is_auto_assign` — migration + seeder + admin CRUD done
- Catalog trash UI (soft delete / restore / force delete) on General catalog pages — frontend in progress
- Dashboard theme infrastructure: Blade `dashboard/shell`, `DashboardThemeResolver`, Vue `themes/theme-1` shells + themed views paths
- **Notification System** (migrated from Jawad, 2026-09-23):
  - `app/Notifications/GeneralNotification.php` — DB + Pusher broadcast, multi-lang (resolves locale from notifiable model or app locale, not hardcoded ar/en)
  - `app/Notifications/BroadcastOnlyNotification.php` — Pusher broadcast only (no DB), for ephemeral events
  - `app/Support/helpers.php`: `sendNotification()` and `sendPushNotification()` global helpers
  - `lang/ar/notifications.php` + `lang/en/notifications.php` — notification translation key files (empty, fill per feature)
  - `config/broadcasting.php` — Pusher/Soketi, Reverb, Ably, Redis, log, null drivers
  - `config/services.php` — `onesignal` config block added
  - `.env.example` — Pusher + OneSignal env vars added
  - **NEEDS-DECISION**: `onesignal_player_id` field not yet on User/Provider models; add it when push notifications are implemented per audience
- **Chat module (`Modules/Chat`, 2026-09-29):** the backend is built and tested (23 tests in `tests/Feature/ChatTest.php`). It covers direct chats with message requests, groups and roles, every message type including the wallet transfer receipt and wallet QR cards, ticks, reply, forward, edit, delete, reactions, stars, pins, disappearing messages, contacts (sync, number lookup, QR), privacy and blocks, presence and typing, folders, LiveKit calls, OneSignal push, and admin `chat-settings`. See [chat-plan.md](chat-plan.md) and [modules/chat](modules/chat/README.md). **Android chat is built** (`ui/screens/chat`: list, conversation, info, new chat and group, QR, privacy, starred, calls, all animated) and compiles against `pusher-java-client` 2.4.4 and `livekit-android` 2.5.0 (JitPack repo added for LiveKit). **Since then (2026-09-28):** Stories, OneSignal push with deep links and full-screen incoming calls, admin themes, report reasons and reports, the admin chat-settings screen, group video grid, and the **web chat** at `/user/messages` (the same API mounted under `/api/user/v1/chat`). Tests: `ChatTest` 27, `ChatStoryTest` 9, `ChatThemeReportTest` 7. The open list is in [chat-tasks.md](chat-tasks.md). **Design system (2026-09-29):** the Android chat takes its colours from the appearance tokens (`Ch.palette`), the admin-chosen font now applies app-wide (`ui/theme/AppFont.kt`), and all fields use the shared field design (`DorrTextField` / chat `ChField`, with icons and a show/hide toggle on passwords).
- **Catalog content (2026-09-30):** service categories carry `audiences` (`ServiceAudience`), a translatable `description` and drag-and-drop ordering; FAQs are ordered per service by drag-and-drop; a shared rich-text editor/renderer backs FAQ answers and legal pages; mobile reads `GET /api/mobile/v1/faqs` and `/legal-pages`; Android added `ServiceDetailScreen` and `HtmlText`
- **Android docs (2026-09-30):** [modules/android/README.md](modules/android/README.md) documents app structure, backend host switch and rules
- **Working agreement (2026-09-30):** plan first for any project; pick model and effort by task size — see [AI-INSTRUCTIONS.md](AI-INSTRUCTIONS.md#model-and-effort-selection)
- **UNKNOWN:** No other active work tracked in repo

---

## Pending Work

| Priority | Item | Status |
|----------|------|--------|
| High | Expand API/feature test coverage | TODO |
| High | Define admin permissions (Spatie) | NEEDS-DECISION |
| Medium | Public website scope | NEEDS-DECISION |
| Medium | Production deployment documentation | TODO |
| Low | Frontend ESLint/Prettier setup | TODO |
| Low | Implement `useAuth` / `usePermission` stubs | Blocked on permissions |
| Low | Centralized frontend API service layer | Optional refactor |

---

## Known Issues

| Issue | Severity | Notes |
|-------|----------|-------|
| Spatie Permission unused | Medium | Roles not enforced |
| Empty composables (`useAuth`, `usePermission`) | Low | Stubs |
| Empty services (`api.js`, `auth.service.js`) | Low | Stubs |
| No frontend tests | Medium | PHPUnit only |
| Module web routes vs SPA | Low | Legacy resource routes may be unused |
| `CatalogTranslationsTest` (3 tests) fail | Medium | Came with the merge from a colleague's branch (service category name / name-only entity); not touched by the chat work — NEEDS-DECISION who fixes them |

---

## Tests Status

| Suite | Status | Count |
|-------|--------|-------|
| PHPUnit | 674 passing, 3 failing (`CatalogTranslationsTest`, pre-existing — see Known Issues) | 677 tests, 3472 assertions (2026-10-04) |
| Frontend | Not configured | 0 |

**Command:** `composer test` or `php artisan test`

---

## Documentation Status

| Area | Status |
|------|--------|
| Core docs (01–16) | Created |
| AI instructions | Created |
| Module docs | Created (5 modules) |
| API spec | Matches current routes |
| Data model | Matches migrations |

**Rule:** Update docs when changing architecture, API, or schema.

---

## Security Status

| Control | Status |
|---------|--------|
| Sanctum token auth | Active |
| Password hashing | Laravel default |
| Validation on Form Requests | Active |
| CSRF on web forms | N/A for token API |
| Rate limiting | **UNKNOWN** — not confirmed on auth routes |
| Permission enforcement | Not active |
| Secrets in repo | `.env.example` only (no secrets) |

---

## Next Step (Recommended)

1. Review `docs/01-PRODUCT-REQUIREMENTS.md` open questions with product owner
2. Decide Spatie Permission strategy
3. Add feature tests for admin login + one catalog CRUD flow
4. Decide public website scope before implementing `modules/website`

---

## Quick Start Commands

```bash
composer setup          # Full install + migrate + npm build
composer dev            # Server + queue + logs + vite
composer test           # Run PHPUnit
php artisan route:list  # Verify API routes
npm run dev             # Vite dev server only
npm run build           # Production frontend build
```
