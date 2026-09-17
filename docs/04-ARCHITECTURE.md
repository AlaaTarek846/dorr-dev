# Architecture

**Last updated:** 2026-09-17

---

## Overall System Architecture

```
┌──────────────────────────────────────────────────────────────────────────┐
│                            Client (Browser)                               │
├─────────────────┬─────────────────────┬──────────────────────────────────┤
│ Admin SPA       │ User SPA            │ Provider SPA                     │
│ /admin          │ /user               │ /provider                        │
│ adminAxios      │ userAxios           │ providerAxios                    │
│ PrimeVue        │ Pinia + AI chat     │ Pinia (no AI chat)               │
└────────┬────────┴──────────┬──────────┴──────────────┬───────────────────┘
         │                   │                         │
         ▼                   ▼                         ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                 Laravel 12 Application (Monolith + Modules)               │
├──────────────────────────────────────────────────────────────────────────┤
│  routes/web.php (Blade SPA shells)  │  Module API routes (/api/...)      │
│  /admin | /user | /provider         │  admin/v1 | user/v1 | provider/v1│
├─────────────────────────────────────┴────────────────────────────────────┤
│  app/ (shared core)              │  Modules/ (bounded features)          │
│  General/ catalog                │  Admin, User, AI, Provider            │
│  BaseRepository/Service            │                                       │
│  Support/Api, Auth services        │                                       │
├──────────────────────────────────┴───────────────────────────────────────┤
│  Eloquent Models │ MySQL/SQLite │ Sanctum │ Spatie Media                │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Backend Architecture

### Request Lifecycle (API)

1. HTTP request → `/api/{audience}/v1/...`
2. Module `RouteServiceProvider` applies `api` middleware stack + prefix `api`
3. Route group middleware: `locale` → `guest:*` or `auth:*`
4. Controller method invoked
5. Form Request validates input (when type-hinted)
6. Service executes business logic
7. Repository interacts with Eloquent / DB
8. API Resource shapes output (when used)
9. `ApiResponse` returns JSON envelope

### Module Architecture

| Module | Responsibility | Route files |
|--------|----------------|-------------|
| **Admin** | Admin auth, admin CRUD, mounts General catalog routes | `routes/admin.php` |
| **User** | User auth/registration/profile, user API | `routes/dashboard.php` |
| **AI** | AI providers (admin), AI chat (user) | `routes/admin.php`, `routes/user.php` |
| **Provider** | Provider profiles (admin CRUD) + provider portal auth/profile | `routes/admin.php`, `routes/dashboard.php` |

Module routes are included from each module's `routes/api.php` → loaded by `RouteServiceProvider`.

### Shared Core (`app/`)

**Purpose:** Cross-audience domain logic and infrastructure.

```
app/
├── Enums/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── CatalogController.php
│   │   └── General/              ← shared catalog controllers
│   ├── Middleware/
│   ├── Requests/
│   │   ├── Concerns/
│   │   └── General/
│   └── Resources/
│       ├── Concerns/
│       └── General/
├── Mail/
├── Models/
│   └── Concerns/
├── Providers/
├── Repositories/
│   ├── BaseRepository.php
│   ├── TranslatableRepository.php
│   ├── Concerns/
│   └── General/
├── Services/
│   ├── BaseService.php
│   ├── CatalogService.php
│   ├── ExchangeRateService.php
│   ├── Auth/
│   ├── Concerns/
│   └── General/
├── Support/
│   ├── Api/
│   ├── Media/
│   ├── helpers.php
│   └── LocaleResolver.php
└── Traits/
```

### Catalog Inheritance Chain

```
Controller: CatalogController → General\{Entity}Controller
Service:    CatalogService    → General\{Entity}Service
Repository: TranslatableRepository → General\{Entity}Repository
Request:    HasCatalogRules trait → General\{Entity}Request
Resource:   FormatsTranslations  → General\{Entity}Resource
```

---

## Frontend Architecture

### Admin SPA

```
resources/js/
├── app.js → main.js
├── App.vue
├── router/index.js          (base: /admin)
├── modules/admin/routes.js
├── modules/admin/views/     (feature pages)
├── layouts/AdminLayout.vue
├── composables/             (CRUD, validation)
├── stores/                  (Pinia)
└── api/adminAxios.js
```

### User SPA

```
resources/js/
├── apps/user/user-app.js
├── UserApp.vue
├── router/user-index.js     (base: /user)
├── modules/user/routes.js
├── modules/user/views/
├── layouts/UserLayout.vue
└── api/userAxios.js
```

### Provider SPA

```
resources/js/
├── apps/provider/provider-app.js
├── apps/provider/ProviderApp.vue
├── router/provider-index.js   (base: /provider)
├── modules/provider/routes.js
├── modules/provider/views/    (auth, dashboard, profile)
├── layouts/provider/ProviderLayout.vue
├── layouts/provider/ProviderSidebar.vue   (no AI chat)
├── stores/providerAuth.js     (token: provider_token)
├── stores/providerServiceSelection.js
└── api/providerAxios.js
```

### Shared Frontend (all SPAs)

- `components/` — auth, catalog, chat, layout, ui
- `composables/` — validation, toast, header
- `locales/` — ar, en
- `plugins/i18n.js`
- `utils/`

### Frontend State Flow

```
User action → Vue component
  → composable (crudStructure / useValidation)
    → Pinia store (optional: counts, auth, locale)
      → axios client (adminAxios / userAxios / providerAxios)
        → Laravel API
          → JSON response
            → toast / form feedback / reactive state update
```

---

## Database Architecture

- Single relational database (MySQL in dev `.env.example`, SQLite in tests)
- Core tables in `database/migrations/`
- Module tables in `Modules/*/database/migrations/`
- Translation pattern: `{entity}` + `{entity}_translations` tables
- Polymorphic: `social_accounts`, `verification_codes`
- Spatie: `permissions`, `roles`, `model_has_*`, `media`

See [05-DATA-MODEL.md](./05-DATA-MODEL.md).

---

## Authentication Architecture

```
┌─────────────┐     Sanctum Token      ┌──────────────┐
│  Admin SPA  │ ─────────────────────► │ admin_api    │──► admins
└─────────────┘                        └──────────────┘

┌─────────────┐     Sanctum Token      ┌──────────────┐
│  User SPA   │ ─────────────────────► │ user_api     │──► users
└─────────────┘                        └──────────────┘

┌─────────────┐     Sanctum Token      ┌──────────────┐
│ Provider SPA│ ─────────────────────► │ provider_api │──► providers
└─────────────┘                        └──────────────┘

All guards use personal_access_tokens (Sanctum).
```

OAuth (Google/Apple) uses web redirect flow → creates/links `social_accounts` → issues Sanctum token.

**Shared OAuth callback:** Google/Apple redirect URI is `/auth/user/{provider}/callback`. Session key `social_auth_panel` (`user` | `provider`) selects User vs Provider handling after callback.

---

## Authorization Architecture

**Current:** Binary authentication (logged in or not) per guard.

**Installed but unused:** Spatie Permission (roles/permissions tables, middleware aliases).

**NEEDS-DECISION:** Permission model for admin features.

---

## Communication: Laravel ↔ Vue

1. Blade views (`admin.blade.php`, `user.blade.php`, `provider.blade.php`) boot SPAs with branding JSON
2. Vue apps use relative API paths (`/api/admin/v1/...`, `/api/user/v1/...`, `/api/provider/v1/...`)
3. Vite HMR in dev (`127.0.0.1:5173`); built assets in production
4. CSRF: API uses token auth (not cookie SPA CSRF for API calls)
5. Locale: `X-Locale` header on all API requests

---

## Important Dependencies

| Package | Role |
|---------|------|
| nwidart/laravel-modules | Module isolation |
| laravel/sanctum | API authentication |
| spatie/laravel-medialibrary | File uploads |
| spatie/laravel-permission | Roles (future) |
| laravel/socialite | OAuth |
| primevue | Admin UI components |
| pinia | Frontend state |
| vue-i18n | Translations |

---

## PLANNED (not in codebase)

- `resources/js/modules/website/` — public website SPA
- `routes/public.php` or `/api/public/v1/*` — public read-only API
- `app/Actions/` — single-action classes
- `app/Policies/` — authorization policies
