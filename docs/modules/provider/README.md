# Provider Module

**Path:** `Modules/Provider/`  
**Namespace:** `Modules\Provider\`  
**Last updated:** 2026-09-17  
**Purpose:** Business service provider profiles — admin management **and** provider self-service portal (dashboard SPA).

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
| Routes | `routes/admin.php`, `routes/dashboard.php` (included from `routes/api.php`) |
| Resources | `ProviderResource`, `ProviderServiceResource` |

### Frontend

| Area | Path |
|------|------|
| SPA entry | `resources/js/apps/provider/provider-app.js` |
| Root component | `resources/js/apps/provider/ProviderApp.vue` |
| Router | `resources/js/router/provider-index.js` (base `/provider`) |
| Views | `resources/js/modules/provider/views/` |
| Layout | `resources/js/layouts/provider/ProviderLayout.vue`, `ProviderSidebar.vue` |
| Auth store | `resources/js/stores/providerAuth.js` (token key: `provider_token`) |
| Service selection | `resources/js/stores/providerServiceSelection.js` |
| Axios | `resources/js/api/providerAxios.js` |
| Blade shell | `resources/views/provider.blade.php` |

---

## Provider portal scope (implemented)

- Login, sign-up, email verification, create password
- Forgot / reset password (reset link targets `/provider/reset-password`)
- Google / Apple OAuth (`/auth/provider/{google|apple}/redirect`)
- Dashboard + profile (GET `me`, POST `profile`, PUT `profile/password`)
- Header service dropdown + per-service sidebar links (from `services[]` on provider payload)
- i18n keys: `provider_dashboard.*` in `resources/js/locales/ar.json` and `en.json`

---

## Not in Provider portal

- **No AI Chat** — no `/api/provider/v1/ai-chat/*`, no chat views in provider SPA
- No provider-facing CRUD for other providers (admin only)

---

## Related docs

- [API.md](./API.md) — admin + dashboard endpoints
- [TECHNICAL-SPECIFICATION.md](./TECHNICAL-SPECIFICATION.md) — auth, OAuth, frontend wiring
- [TESTING.md](./TESTING.md) — manual auth checklist
- [Global API spec](../../06-API-SPECIFICATION.md)
