# Technical Specification

> Factual description of the current implementation.

---

## Stack Versions

| Component | Version (from `composer.json` / `package.json`) |
|-----------|--------------------------------------------------|
| PHP | ^8.2 |
| Laravel | ^12.0 |
| Laravel Sanctum | ^4.0 |
| nwidart/laravel-modules | ^12.0 |
| spatie/laravel-permission | ^6.25 |
| spatie/laravel-medialibrary | ^11.23 |
| laravel/socialite | ^5.31 |
| socialiteproviders/apple | ^6.0 |
| Vue | ^3.5.42 |
| Vue Router | ^5.3.1 |
| Pinia | ^4.0.3 |
| PrimeVue | ^4.3.6 |
| vue-i18n | ^11.4.10 |
| Vuelidate | ^2.0.3 |
| Vite | ^7.0.7 |
| Tailwind CSS | ^4.0.0 |
| PHPUnit | ^11.5.50 |
| Laravel Pint | ^1.24 |

---

## Backend Architecture

### Layering Pattern

```
Route → Controller → Service → Repository → Model
              ↓
         Form Request (validation)
              ↓
         API Resource (response shaping)
```

### Shared Base Classes

| Class | Path | Role |
|-------|------|------|
| `BaseRepository` | `app/Repositories/BaseRepository.php` | CRUD, media sync, delete guards |
| `TranslatableRepository` | `app/Repositories/TranslatableRepository.php` | Translations + bulk/status |
| `BaseService` | `app/Services/BaseService.php` | CRUD wrappers → ApiResponse |
| `CatalogService` | `app/Services/CatalogService.php` | Catalog-specific service base |
| `CatalogController` | `app/Http/Controllers/CatalogController.php` | index/show/destroy/dropdown |

### General (Shared Catalog) Namespace

Cross-audience catalog code lives under `App\...\General\`:

- `app/Http/Controllers/General/`
- `app/Services/General/`
- `app/Repositories/General/`
- `app/Http/Requests/General/`
- `app/Http/Resources/General/`

Entities: Country, Currency, Flag, Language, ServiceCategory, PlatformSetting.

### Module Structure (nwidart)

Each module under `Modules/{Name}/`:

```
app/
├── Http/Controllers/
├── Http/Requests/
├── Http/Resources/
├── Models/
├── Repositories/
├── Services/
└── Providers/
routes/
database/migrations/
```

Enabled modules (`modules_statuses.json`): **Admin**, **User**, **AI**, **Provider**.

### Authentication

- **Driver:** Laravel Sanctum (API tokens)
- **Guards:** `admin_api` → Admin model; `user_api` → User model
- **Token creation:** On login in auth controllers
- **Password reset:** Custom OTP/flow token (not Laravel default broker for user API)

Auth utilities in `app/Services/Auth/`:
- `AuthFlowTokenService`
- `VerificationCodeService`
- `SocialAuthService`

### Authorization

- Middleware aliases registered: `role`, `permission`, `role_or_permission` (Spatie)
- **No route-level permission usage found**
- User model uses `HasRoles` trait; no seeders assigning roles found in analysis

### API Architecture

- Module routes mounted under `/api` prefix via module `RouteServiceProvider`
- Audience prefixes: `admin/v1`, `user/v1`
- Locale middleware on main API groups
- Standard response: `App\Support\Api\ApiResponse`

### Pagination / Filtering / Sorting

- Helper: `allOrPaginate()` in `app/Support/helpers.php`
- Paginator meta: `App\Support\Api\ApiPaginator`
- Search/filter: `SearchFilterTrait` on models + `scopeSearchAndFilter`
- Frontend sends search JSON and status filter query params (via `crudStructure.js`)

### File Storage

- Default disk: `FILESYSTEM_DISK` env (default `local`)
- Spatie Media Library table: `media`
- Custom path generator: `App\Support\Media\ModelFolderPathGenerator`
- Platform settings collections: logo, logo_dark, favicon_*, apple_touch_icon, web_manifest
- Service categories: `image` collection

### Third-Party Integrations

| Integration | Usage |
|-------------|-------|
| Open Exchange Rates API | Currency exchange rates (`ExchangeRateService`) |
| OpenAI-compatible APIs | AI chat (via AI connectors) |
| Anthropic, Google, Groq | AI provider connectors |
| Google OAuth | User social login |
| Apple OAuth | User social login (SocialiteProviders) |

### Queues / Jobs / Events

- Queue tables migrated (`jobs`, `job_batches`, `failed_jobs`)
- Default queue: `database` (from `.env.example`)
- **No custom Jobs, Events, or Listeners implemented**

### Caching

- Default cache: `database` (from `.env.example`)
- Exchange rate sync cached with configurable TTL (`config/exchange.php`)

---

## Frontend Architecture

### Dual SPA Setup

| SPA | Entry | Router base | UI library |
|-----|-------|-------------|------------|
| Admin | `resources/js/app.js` | `/admin` | PrimeVue (Aura theme) |
| User | `resources/js/user-app.js` | `/user` | No PrimeVue |

### State Management (Pinia)

Stores in `resources/js/stores/`:
- `auth`, `userAuth` — session tokens
- `locale`, `availableLanguages` — i18n
- `platformBranding`, `toast`
- Catalog count stores: `flags`, `countries`, `currencies`, `languages`, `serviceCategories`
- `users`, `providers`

### Routing

- `resources/js/router/index.js` — admin
- `resources/js/router/user-index.js` — user
- Middleware pipeline: `auth.js`, `guest.js`, `userAuth.js`, `userGuest.js`

### API Clients

- `resources/js/api/adminAxios.js` — Bearer `admin_token`, `X-Locale`
- `resources/js/api/userAxios.js` — Bearer `user_token`, `X-Locale`, `Accept-Language`
- No `baseURL` (relative paths)
- No response interceptors

### Composables (Key)

- `crudStructure.js` — admin list CRUD factory
- `useCatalog.js` — catalog entity wrapper
- `useValidation.js` — Vuelidate + i18n rules
- `useCatalogTranslations.js` — translation tabs in modals

### i18n

- `vue-i18n` with `ar.json`, `en.json`
- Composition API mode (`legacy: false`)
- Direction sync via `utils/direction.js`

### Form Validation

- **Admin modals / profile / settings:** Vuelidate via `useValidation.js`
- **User auth pages:** Manual validation (no Vuelidate)

### Assets

- Legacy dashboard assets loaded dynamically (`useDashboard.js`) from `/dashboard/assets/`
- Vite builds `resources/css/app.css` + JS entries

---

## Error Handling

### Backend

- `App\Exceptions\ApiExceptionRenderer` — JSON errors for API requests
- Validation: 422 with `errors`
- Optional debug block when `debug=1` and `app.debug=true`

### Frontend

- `useToast.js` — displays API error messages
- `useValidation.js` — maps API validation errors to form fields

---

## Testing (Current)

- PHPUnit: `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`, `tests/Feature/ApiExceptionRendererTest.php`
- **No frontend test framework configured**

---

## Not Implemented (confirmed absent)

- `app/Actions/`
- `app/Policies/`
- `app/Jobs/`
- `resources/js/modules/website/`
- ESLint / Prettier configs
- TypeScript
