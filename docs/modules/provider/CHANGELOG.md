# Provider — Changelog

---

## [2026-09-17] — Provider dashboard SPA

### Added

- **Provider portal SPA** at `/provider` (Vue 3 + Pinia + Vue Router)
- Sanctum guard `provider_api` and Eloquent provider `providers` → `Modules\Provider\Models\Provider`
- Dashboard API `/api/provider/v1/*` (`routes/dashboard.php`)
- Controllers: auth, registration, password reset, profile, social OAuth
- Web shell: `provider.blade.php`, Vite entry `resources/js/apps/provider/provider-app.js`
- Frontend: login, sign-up, verify-email, create-password, forgot/reset password, OAuth callback, dashboard, profile
- OAuth: `/auth/provider/{google|apple}/redirect`; shared callback `/auth/user/{provider}/callback` with session `social_auth_panel`
- `providerAxios` + `providerAuth` store (`provider_token`)
- `ProviderLayout` / `ProviderSidebar` (no AI chat)
- Header service dropdown + sidebar links from `services[]` payload
- i18n: `provider_dashboard.*` (ar/en)

### Unchanged / explicit non-goals

- No `/api/provider/v1/ai-chat/*`
- Admin provider CRUD remains under `/api/admin/v1/providers*`

---

## [Unreleased]

### Added

- Module documentation (initial)

### 2026-09-23 — `phone_code` column

- Added nullable `phone_code` (country dial code, e.g. `+20`) to `providers` via `Modules/Provider/database/migrations/2026_09_23_100000_add_phone_code_to_providers_table.php`
- `ProviderResource` exposes `phone_code`; admin provider modal + admin profile persist it; admin providers table renders `phone_code` together with `phone` in the phone cell
- `ProviderRequest` / `ProviderProfileUpdateRequest` accept nullable `phone_code` (stored as `+XX`)

## Historical

See git log for `Modules/Provider/` changes.
