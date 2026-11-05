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

## Providers app platform (establishments, team, vehicles) — phase A of [moving_tasks.md](../../moving_tasks.md)

The shared base for every provider service (moving first). Business rules: [moving_business.md](../../moving_business.md) §5, §6, §12–§17.

- **Account = `Provider`** (a person). The app signs in by phone OTP: `POST provider/v1/auth/otp | auth/verify | auth/resend | auth/logout`. The web portal keeps its email sign-in.
- **Establishment** (`provider_establishments`): individual or company, one owned per person, with its own wallet alias `establishment` (`Modules/Wallet/config` → `morph_map`). Status `draft → under_review → approved / rejected / suspended`.
- **Branches** (`provider_branches`): point + `radius_km`; the main branch is made with the establishment.
- **Memberships** (`provider_memberships` + `_branches` + `_permissions` + `_events`): a person's seat; the owner holds every key. Keys and templates: `Modules/Provider/config/config.php`. Nobody grants a key or branch they don't hold. Exclusivity is shown before accepting; a member leaves any time (`MembershipEnded`).
- **Vehicles & documents** (`provider_vehicle_types`, `provider_document_types` + `_requirements`, `provider_vehicles`, `provider_documents`): the admin sets what each country/service/vehicle type asks; the photo showing the plate (`vehicle_plate_photo`) is always asked. Expiry sweep: `provider:sweep-documents` (daily).
- **Work gate:** `WorkGate::offerProblem()` — no offer before the establishment, the vehicle, its photos and papers (and an individual's own driving licence) are approved.

| App endpoint (`provider/v1`, `auth:provider_api` + phone verified) | Purpose |
|---|---|
| `GET workspace`, `GET workspace/options` | establishments to switch between, invitations; services, vehicle types, permission groups |
| `POST establishments`, `GET/PUT establishment`, `POST establishment/submit` | register, view, edit, send for review |
| `GET/POST branches`, `PUT/DELETE branches/{uuid}` | branches |
| `GET/POST members`, `PUT/DELETE members/{uuid}`, `GET members/events` | team and its log |
| `POST invitations/{uuid}/accept|decline`, `POST memberships/{uuid}/leave` | the invited person's answer, leaving |
| `GET/POST vehicles`, `GET/PUT vehicles/{uuid}`, `POST vehicles/{uuid}/deactivate` | vehicles with required/missing photos and papers |
| `GET/POST documents` | establishment papers, a driver's licence (`driver_uuid`), vehicle papers |

The app sends `X-Establishment: {uuid}` to say which establishment it is working in (needed when the person has more than one).

Admin (`admin/v1`): `provider-establishments` (list, show, `status`, `vehicles/{uuid}/review`, `documents/{uuid}/review`), `provider-vehicle-types`, `provider-document-types` (+ `options`). Permissions: `provider-establishments`, `provider-vehicle-types`, `provider-document-types`.

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
