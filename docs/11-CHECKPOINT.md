# Project Checkpoint


**Last updated:** 2026-10-06  
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

- **Ratings (2026-10-06):** polymorphic `ratings` + mobile API + admin page + Android Play In-App Review; see CHANGELOG.

- **Android Settings — invite / rate / support (2026-10-06):** Settings now has Share with friends, Rate the app, and Support & help (dedicated support live chat via `GET/POST /api/mobile/v1/support-chats`, ticket list + create form, FAQ sheet). Migration `2026_10_06_100000` (tickets) and `2026_10_06_120000` (support messages). **NEEDS-DECISION:** admin ticket inbox and agent chat replies.
- **Country services (2026-10-06):** Admin country create/edit assigns leaf `service_categories` via `country_service_category`. Empty assignment means none; seeding attaches current marketplace leaves to existing countries. Public `/api/general/v1/services` is not filtered.
- **WebP uploads (2026-10-05):** JPEG/PNG stored as WebP in place (`App\Support\Media\WebpUploadConverter` via `HasMediaTrait` plus chat attachments/stories). Stickers, GIF, SVG, fonts, and translation JSON stay as uploaded.

 group slow mode, banned words and invite links that expire; business tools (quick replies, opening hours, welcome / away auto-replies — `config('chat.business_participants')`); scheduled messages (`chat:send-scheduled` every minute — **needs the scheduler**); three notification privacy levels (`notification_privacy`); calls off per country (`calls_disabled_countries`); AI in the chat on a tap (translate, voice to text, summary, suggested replies — `Services\ChatAiService`, admin switch `ai_enabled`; the AI module gained speech to text for OpenAI, Groq and Google). Plus "make a sticker from my photo" (`stickers/mine`, ML Kit on Android) and the logo's navy `#001B53` / orange `#FA7552` as the app's colours. Migrations `2026_10_04_100000`–`100700`. Tests: `ChatModerationTest`, `ChatBusinessTest`, `ChatScheduledTest`, `ChatAiTest`, `ChatEssentialsTest`.

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
- **Admin UI patterns (2026-10-04):** [modules/admin/UI-PATTERNS.md](modules/admin/UI-PATTERNS.md) documents the list / modal / profile layouts taken from `views/country`, `views/user`, `views/profile`
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
