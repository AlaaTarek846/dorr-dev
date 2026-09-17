# Product Requirements

> **Source of truth:** Existing codebase as of documentation creation.  
> **Project name:** Dorr (from platform settings / branding; `APP_NAME` in `.env.example` defaults to Laravel)

---

## Project Purpose

Dorr is a Laravel + Vue.js platform that provides:

1. An **admin dashboard** (`/admin`) for platform configuration, catalog management, user/admin management, AI provider settings, and service provider management.
2. A **user dashboard** (`/user`) for end-user authentication, registration, profile management, and AI chat.
3. Shared **platform catalog data** (countries, flags, languages, currencies, service categories) used across admin and user experiences.

There is **no public marketing website SPA/API** implemented yet (only `welcome.blade.php` at `/`).

---

## Main Business Objective

Build and operate a multi-audience platform with:

- Centralized platform settings and branding
- Multilingual catalog/reference data
- Separate admin and user authentication flows
- AI chat for authenticated users
- Service provider registry managed by admins

**NEEDS-DECISION:** Full product vision, market positioning, and long-term business goals beyond implemented features.

---

## Target Users

| User Type | Access | Status |
|-----------|--------|--------|
| **Platform Admin** | Admin SPA + `/api/admin/v1/*` | Implemented |
| **End User** | User SPA + `/api/user/v1/*` | Implemented |
| **Public Website Visitor** | `/` welcome page only | Partial (no website module) |
| **Service Provider (self-service)** | — | Not implemented (providers managed by admin only) |

---

## User Types (Technical)

| Actor | Model | Guard | Token storage (frontend) |
|-------|-------|-------|--------------------------|
| Admin | `Modules\Admin\Models\Admin` | `admin_api` (Sanctum) | `localStorage.admin_token` |
| User | `Modules\User\Models\User` | `user_api` (Sanctum) | `localStorage.user_token` |

---

## Existing Features

### Implemented

#### Platform / General (shared catalog)
- Flags CRUD + translations + status + bulk delete + dropdown
- Languages CRUD + translations + dashboard default locale + dropdown
- Currencies CRUD + translations + exchange rate sync + dropdown
- Countries CRUD + translations + flag/currency linkage + dropdown
- Service categories (tree, leaf options, image media, translations)
- Platform settings (app name, logo/favicon/media assets, branding endpoint)

#### Admin module
- Admin login/logout/check-token/me
- Admin profile update + password change
- Admin accounts CRUD + status + bulk delete
- User accounts CRUD (from admin) + status + bulk delete
- AI provider configuration (OpenAI, Anthropic, Google, Groq)
- Provider (business) CRUD + status + bulk delete

#### User module
- Email registration with OTP verification
- Password creation after verification
- Login/logout/check-token/me
- Forgot/reset password flow
- Profile update + password change
- Social login (Google, Apple) via web redirect/callback
- Countries dropdown (authenticated)

#### AI module
- Admin: list/update/test/set-default AI providers
- User: AI chat status, conversations CRUD, send messages

#### Provider module
- Admin: provider profiles linked to countries and service categories

#### Frontend
- Admin SPA: catalog pages, users, providers, AI settings, platform settings, profile
- User SPA: auth flows, dashboard, profile, AI chat
- i18n: Arabic + English
- PrimeVue (admin only), Pinia, Vue Router, Vuelidate (forms)

### In Progress

**UNKNOWN** — no explicit in-progress tracking in codebase.

### Planned

**NEEDS-DECISION** — no roadmap file existed before this documentation system.

Likely planned (inferred from architecture gaps, **not confirmed**):
- Public website module (`resources/js/modules/website/` does not exist)
- Public read-only API (`/api/public/v1/*` does not exist)

Mark as **NEEDS-DECISION**, not implemented.

### Future

- Role/permission enforcement in routes (Spatie installed but not wired to routes)
- Frontend permission composable (`usePermission.js` is empty stub)
- Centralized API service layer on frontend (stubs exist)

---

## Functional Requirements (Confirmed)

### Authentication
- Admins authenticate via email/password; receive Sanctum token
- Users authenticate via email/password or social OAuth
- Users register with email OTP verification before password setup
- Separate guest/auth middleware per audience (`guest:admin_api`, `guest:user_api`)

### Localization
- API accepts locale via middleware (`locale`)
- Supported locales in backend: `ar`, `en` (`LocaleResolver`)
- Frontend stores locale in localStorage; sends `X-Locale` header

### Catalog
- Translatable entities require translations for all "storable" language locales
- Bulk delete and status change supported on catalog entities
- Delete blocked when related records exist (e.g., country blocked if admins linked)

### Media
- Platform settings, service categories, users, admins, providers support Spatie Media Library uploads

### AI
- Multiple AI providers configurable; one default provider
- Users can create conversations and send messages through configured gateway

---

## Non-Functional Requirements

| Requirement | Current State |
|-------------|---------------|
| PHP ^8.2 | Configured in `composer.json` |
| Laravel ^12 | Configured |
| API JSON responses | Standardized via `App\Support\Api\ApiResponse` |
| Pagination | Supported via `allOrPaginate()` + `ApiPaginator` |
| SQLite for tests | `phpunit.xml` uses in-memory SQLite |
| Queue worker in dev | `composer dev` runs `queue:listen` |
| Exchange rate caching | `ExchangeRateService` caches sync results |

**TODO:** Production SLA, performance targets, scalability requirements — not documented in codebase.

---

## Business Requirements

**NEEDS-DECISION** — formal business rules document not found in repository.

Observed business rules from code:
- Languages can be marked `stores_translation` — controls which locales appear in catalog translation validation
- Service categories can be hierarchical (parent/children) with `requires_provider` flag on leaf categories
- User status enum: Active, Inactive, Blocked
- Verification codes are polymorphic (email verification, password reset flows)

---

## Current Limitations

1. No public website frontend or API
2. Spatie Permission installed but **not used in routes** (no role/permission checks on endpoints)
3. `useAuth` and `usePermission` composables are empty stubs
4. `services/api.js` and `services/auth.service.js` are empty stubs
5. No Jobs/Events/Policies/Actions layer in use
6. No frontend tests (ESLint/Prettier not configured)
7. Module web routes (`Route::resource`) exist but primary UX is SPA + API
8. Provider module has no end-user-facing interface

---

## Known Problems

**UNKNOWN** — no issue tracker referenced in repo. Document issues as discovered during development.

---

## TODO Items

- [ ] Define product roadmap (`08-ROADMAP.md` — initial version created)
- [ ] Decide public website scope (**NEEDS-DECISION**)
- [ ] Decide Spatie Permission rollout strategy (**NEEDS-DECISION**)
- [ ] Implement frontend permission layer when backend permissions are defined
- [ ] Add API/integration tests for catalog CRUD and auth flows
- [ ] Document production deployment process (`14-DEPLOYMENT.md`)

---

## Open Questions

1. What is the public website scope and timeline? (**NEEDS-DECISION**)
2. Will service providers get a self-service portal? (**NEEDS-DECISION**)
3. What roles/permissions are required for admins? (**NEEDS-DECISION**)
4. Is multi-tenancy planned? (**NEEDS-DECISION**)
5. What is the production hosting/deployment target? (**NEEDS-DECISION**)
