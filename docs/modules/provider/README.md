# Provider Module

**Path:** `Modules/Provider/`  
**Namespace:** `Modules\Provider\`  
**Last updated:** 2026-09-20  
**Purpose:** Business service provider profiles — admin management **and** provider self-service portal (dashboard SPA, no AI chat).

---

## Audiences

| Audience | Entry | API base | Guard |
|----------|-------|----------|-------|
| Admin | `/admin/providers` | `/api/admin/v1/providers*` | `admin_api` |
| Provider (portal) | `/provider` | `/api/provider/v1/*` | `provider_api` |

---

## Components

### Backend

| Layer | Classes |
|-------|---------|
| Models | `Provider`, `ProviderService` |
| Admin | `ProviderController` → `ProvidersService` → `ProviderRepository` |
| Dashboard auth | `ProviderAuthController`, `ProviderRegistrationController`, `ProviderPasswordResetController`, `ProviderProfileController`, `ProviderSocialAuthController` |
| Routes | `routes/api.php` → `admin.php` + `dashboard.php` |
| Resources | `ProviderResource`, `ProviderServiceResource` |

**Model (`Provider`):** `HasApiTokens`, `HasSocialAccounts`, `HasVerificationCodes`, media, soft deletes.

### Frontend

| Area | Path |
|------|------|
| SPA entry | `resources/js/apps/provider/provider-app.js` |
| Root component | `resources/js/apps/provider/ProviderApp.vue` |
| Router | `resources/js/router/provider-index.js` (history base `/provider`) |
| Routes | `resources/js/modules/provider/routes.js` (`resolveShell('provider')`, `resolvePage('provider', …)`) |
| Themed views | `resources/js/modules/provider/themes/{path}/views/` (default: `theme-1`) |
| Shell layout | `resources/js/layouts/themes/{path}/ProviderShell.vue` |
| Legacy wrapper | `resources/js/layouts/provider/ProviderLayout.vue` → delegates to shell |
| Chrome | `components/layout/provider/ProviderHeader.vue`, `ProviderSidebar.vue`, `ProviderServiceSelect.vue` |
| Auth store | `resources/js/stores/providerAuth.js` (`localStorage`: `provider_token`) |
| Service selection | `resources/js/stores/providerServiceSelection.js` |
| Axios | `resources/js/api/providerAxios.js` (Bearer + `X-Locale`) |
| Blade | `resources/views/provider.blade.php` → `dashboard/shell.blade.php` + `DashboardThemeResolver` |
| i18n | `provider_dashboard.*` in `resources/js/locales/ar.json`, `en.json` |
| Vite | `vite.config.js` input includes `provider-app.js` |

---

## Provider portal — auth flows (implemented)

Same patterns as User portal; **no AI chat**.

| Flow | SPA route | API |
|------|-----------|-----|
| Login | `/provider/login` | POST `/api/provider/v1/login` |
| Sign-up | `/provider/sign-up` | POST `/api/provider/v1/register` |
| Verify email | `/provider/verify-email` | POST `verify-email`, `resend-verification` |
| Create password | `/provider/create-password` | POST `/api/provider/v1/create-password` |
| Forgot password | `/provider/forgot-password` | POST `/api/provider/v1/forgot-password` |
| Reset password | `/provider/reset-password` | POST `/api/provider/v1/reset-password` (email link targets this SPA path) |
| Google / Apple | Buttons on login/sign-up | GET `/auth/provider/{google\|apple}/redirect` |
| Dashboard | `/provider/dashboard` | — (auth) |
| Profile | `/provider/profile` | GET `/me`, POST `/profile`, PUT `/profile/password` |

---

## OAuth notes

- **Single redirect URI** in Google/Apple console: `{APP_URL}/auth/user/{provider}/callback` (shared with User).
- Session `social_auth_panel` = `provider` selects Provider handling after callback.
- Alternate route: `/auth/provider/{provider}/callback` (same controller; production uses user callback URI per `config/services.php`).
- SPA landing: `/provider/oauth/callback`.
- One OAuth identity → one `social_accounts` row (`provider`, `provider_id` unique) → one authenticatable in the system.

---

## Not in Provider portal

- **No AI Chat** — no `/api/provider/v1/ai-chat/*`, no chat views in provider SPA
- No provider-facing CRUD for other providers (admin only)

---

## Related docs

- [API.md](./API.md) — admin + dashboard endpoints
- [TECHNICAL-SPECIFICATION.md](./TECHNICAL-SPECIFICATION.md) — auth, OAuth, frontend wiring
- [TESTING.md](./TESTING.md) — manual auth checklist
- [REQUIREMENTS.md](./REQUIREMENTS.md) — business rules + NEEDS-DECISION
- [Global API spec](../../06-API-SPECIFICATION.md)
