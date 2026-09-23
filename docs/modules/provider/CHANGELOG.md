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

## [2026-09-20] — Documentation sync

### Updated

- Module docs aligned with codebase: `apps/provider/provider-app.js`, themed views under `themes/theme-1/views`, `ProviderShell`, shared `dashboard/shell.blade.php`
- Global API spec: provider SPA route table, admin provider restore/force endpoints
- Architecture + checkpoint: three SPAs, provider OAuth and guest JSON guard

---

## [Unreleased]

### Added

- Module documentation (initial)

## Historical

See git log for `Modules/Provider/` changes.
