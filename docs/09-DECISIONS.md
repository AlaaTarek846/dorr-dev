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
