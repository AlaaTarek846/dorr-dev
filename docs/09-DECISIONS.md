# Architecture Decision Records (ADR)

> Only document decisions evidenced by the codebase. Undocumented decisions marked **NEEDS-DECISION**.

---

## ADR-001: Laravel + Vue.js Dual SPA

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Context** | Platform serves distinct admin and user audiences |
| **Decision** | Separate Vue SPAs mounted at `/admin` and `/user` via Blade shells |
| **Evidence** | `resources/js/app.js`, `user-app.js`, `routes/web.php` |
| **Consequences** | Two build entries, two routers, two axios clients; shared components/composables |

---

## ADR-002: nwidart/laravel-modules

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Context** | Feature boundaries: Admin, User, AI, Provider |
| **Decision** | Use `nwidart/laravel-modules` with composer merge-plugin |
| **Evidence** | `Modules/`, `modules_statuses.json`, module ServiceProviders |
| **Consequences** | Module-scoped migrations, routes, models; shared logic stays in `app/` |

---

## ADR-003: Sanctum Token Authentication (Dual Guard)

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Separate Sanctum guards: `admin_api`, `user_api` |
| **Evidence** | `config/auth.php`, auth controllers, `personal_access_tokens` |
| **Consequences** | Tokens in localStorage; no cookie-based SPA session for API |

---

## ADR-004: Repository + Service Layer

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Controllers delegate to Services; Services use Repositories |
| **Evidence** | `BaseRepository`, `BaseService`, module services/repos |
| **Consequences** | Consistent CRUD; catalog entities share `CatalogService` / `TranslatableRepository` |

---

## ADR-005: General/ Namespace for Shared Catalog

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Date** | 2026-09-17 (from refactor in conversation history) |
| **Context** | Catalog entities used by admin (and partially user) |
| **Decision** | Place shared catalog Controllers/Services/Repositories/Requests/Resources under `General/` subnamespace |
| **Evidence** | `app/Http/Controllers/General/`, etc. |
| **Consequences** | Clear separation from module-specific code; routes import `General\*` classes |

---

## ADR-006: Standardized API Response Envelope

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | All API responses via `App\Support\Api\ApiResponse` |
| **Evidence** | `ApiResponse.php`, `ApiExceptionRenderer.php`, tests |
| **Consequences** | Frontend can rely on `success`, `message`, `data`, `pagination`, `errors` |

---

## ADR-007: Translation Tables Pattern

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Separate `{entity}_translations` tables; sync via `SyncsTranslations` concern |
| **Evidence** | Migrations, `TranslatableRepository`, `HasTranslations` model concern |
| **Consequences** | Validation requires all storable locales; language changes purge invalid translations |

---

## ADR-008: Spatie Media Library for Uploads

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Use Spatie Media Library with custom path generator |
| **Evidence** | `HasMediaTrait`, `media` migration, platform settings, avatars |
| **Consequences** | Files in `storage/`; API returns media URLs |

---

## ADR-009: PrimeVue for Admin UI Only

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Register PrimeVue in admin `main.js` only |
| **Evidence** | `main.js` vs `user-main.js` |
| **Consequences** | User SPA uses different/lighter UI patterns |

---

## ADR-010: crudStructure Composable for Admin Lists

| Field | Value |
|-------|-------|
| **Status** | Accepted (implemented) |
| **Decision** | Shared CRUD list logic in `composables/crudStructure.js` |
| **Evidence** | Used by catalog, users, providers admin pages |
| **Consequences** | Fast CRUD page development; must follow existing options API |

---

## ADR-011: Interface Translation Files via Media Library

| Field | Value |
|-------|-------|
| **Date** | 2026-09-30 |
| **Status** | Accepted (implemented) |
| **Context** | New interface languages (e.g. `fr`) must be added from the Dashboard without code changes or rebuilds |
| **Decision** | `ar` / `en` stay in the repo (`lang/*`, bundled Vue JSON). Other languages get JSON files per platform/group stored with Spatie Media Library (`TranslationFile`, collections `draft` / `published`). Flow: Upload → Validate → Draft → Publish. `en` is the base and fallback. Backend loads published JSON through a decorating `TranslationLoader`; `LocaleResolver::supported()` includes active languages with a published backend file; Vue fetches `/api/general/v1/translations/{code}/vue` at runtime. Android also loads published strings at runtime (2026-10-01): the app lists languages from `/api/general/v1/translations/languages?platform=android`, downloads `/api/general/v1/translations/{code}/android` only when a non-bundled language is chosen, keeps that single file (`filesDir/translations/{code}.json`, metadata in `dorr_app_prefs`), and serves it through a `Resources` wrapper (`DynamicResources`) that falls back to the bundled English. The XML ZIP export stays available |
| **Reason** | Reuses existing storage/permissions; uploaded files are data only (JSON/CSV parsed, never executed). Android languages reach installed apps without a new release |
| **Consequences** | Cache invalidated by a generation counter on publish / language change. No revisions, no per-key editor, no XLSX. Android base strings must be available at `translations.android_res_path` for Android groups. Android: one downloaded language on the device; the stored file works offline, a newer `android_version` is fetched on launch / when the language dialog opens, and a language no longer offered falls back to `en`. Strings built outside Compose (notifications, foreground services, `applicationContext.getString`) stay in English for downloaded languages. AAB language splits are disabled so ar/en are always installed |

---

## ADR-012: Call Recording via LiveKit Egress, with Everyone's Consent

| Field | Value |
|-------|-------|
| **Date** | 2026-10-11 |
| **Status** | Accepted (implemented) |
| **Context** | Spec 92 and safety rule 1: record voice and video calls only with the explicit yes of every party, never when anyone refuses or doesn't answer, and with a clear indicator the whole time. Calls run on LiveKit Cloud |
| **Options** | 1. Record on the phone (voice only; mixing remote audio is fragile, video isn't practical). 2. **LiveKit Egress** (room composite) into object storage |
| **Decision** | LiveKit Egress `StartRoomCompositeEgress` → one MP4 (camera grid for video, voices for voice) uploaded straight into a **private S3-compatible bucket** (`CHAT_RECORDINGS_*`: AWS S3, Cloudflare R2, Spaces, MinIO). Consent: a recording starts only when every person currently in the call has an `accepted` row. A request lasts 30 s, and one "no" ends it. Joining a call that records or asks to is refused (`409`) without `recording_consent`, which is that person's yes. Anyone in the call stops it. Only people with an `accepted` row play it, through presigned links (15 min). Retention is an admin setting (default 30 days), purged by `chat:call-recordings` |
| **Reason** | The only way to record video, it needs nothing on the phone, and the server alone decides when recording starts. LiveKit Cloud has no storage of its own. The links and the delete are signed with AWS Signature V4 in a few lines (`Support\RecordingStorage`), like the LiveKit JWT, so there's no AWS SDK |
| **Consequences** | Needs a bucket and its keys (no button without them). LiveKit bills egress minutes. Completion is polled (`ListEgress`, every minute, and when someone opens the recordings) rather than a LiveKit webhook. Recordings aren't end-to-end encrypted (calls aren't either) |

---

## ADR-013: Every AI Tool Needs a DORR AI Plan (Chat Included)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-15 |
| **Status** | Accepted (Ali Khalifa, AI module owner) |
| **Context** | The chat's AI tools (translate, voice to text, summary, smart replies, assistant, …, Discover's AI search, greetings, portal translations) ran free. The DORR Chat spec says the chat is free with no paid usage limit ("نموذج التمويل"); DORR AI has its own plans (363–366). |
| **Problem** | AI calls cost money on every use, and images (spec 50, 51) had been held back for cost and abuse. |
| **Options** | 1. Keep chat AI free. 2. Put every AI tool behind the DORR AI plan, the chat core staying free. |
| **Decision** | 2. Every AI task for a person goes through `AiTaskRunner`, which applies `AiChatUsageGuard` exactly like the DORR AI chat: an active plan or the admin's free trial, within its usage window and cooldown, using that window up. Images also count against the plan's daily images (`AiMediaQuotaService`). Exempt: support's automatic FAQ answer (`free: true`) — the platform runs it, nobody asks for it. The chat core (messages, calls, files…) stays free. |
| **Reason** | Owner's decision; one plan for all of DORR AI, wherever it is used. Plan limits and daily image quotas make spec 50/51 safe to offer. |
| **Consequences** | Chat errors `chat_ai_plan_{reason}` (402), `chat_ai_image_not_in_plan` (402), `chat_ai_image_daily_limit` (429); `GET chat/ai` returns `plan {allowed, reason}` read-only (`AiChatUsageGuard::preview()` — opening the chat starts nothing). The app keeps the AI buttons and, on a refusal, offers the DORR AI subscription. This overrides the spec's "free chat" line for the AI tools only. |
| **Rides (2026-10-22, Block 06)** | The same rule in rides: the rider's booking assistant and suggested category, the captain's DORR Driver AI and demand forecast need a plan (`rides_ai_plan_{reason}`, 402); captains subscribe to the same plans (`driver/v1/ai-subscription`, owner_type `driver`). DORR's own reading for its team (case triage, the quality report) is `free`; dispatch, fraud signs, safety checks, cancel risk, ETA and lateness warnings are operational and work for everyone. |

## NEEDS-DECISION

| Topic | Question |
|-------|----------|
| Spatie Permission usage | Which roles/permissions? Route enforcement strategy? |
| Public website architecture | SSR Blade, Vue SPA, or separate app? |
| API versioning beyond v1 | When to introduce v2? |
| Queue strategy for AI | Sync vs async message processing? |
| Production deployment target | Server, CI/CD, hosting provider? |
| Commit convention enforcement | Husky/pre-commit hooks? |

---

## ADR Template (for future decisions)

```markdown
## ADR-XXX: Title

| Field | Value |
|-------|-------|
| **Date** | YYYY-MM-DD |
| **Status** | Proposed / Accepted / Deprecated |
| **Context** | ... |
| **Problem** | ... |
| **Options** | 1. ... 2. ... |
| **Decision** | ... |
| **Reason** | ... |
| **Consequences** | ... |
```
