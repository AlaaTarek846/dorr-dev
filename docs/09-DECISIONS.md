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
