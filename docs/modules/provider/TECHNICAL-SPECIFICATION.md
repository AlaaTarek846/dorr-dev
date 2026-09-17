# Provider — Technical Specification

**Last updated:** 2026-09-17

---

## Authentication

### Guard & provider (`config/auth.php`)

```php
'provider_api' => [
    'driver' => 'sanctum',
    'provider' => 'providers',
],
'providers' => [
    'driver' => 'eloquent',
    'model' => Modules\Provider\Models\Provider::class,
],
```

### `Provider` model traits

- `HasApiTokens` (Sanctum)
- `HasSocialAccounts` (polymorphic `social_accounts`)
- `HasVerificationCodes` (registration / reset flows)
- `HasMediaTrait`, `SoftDeletes`, `SearchFilterTrait`

---

## Route wiring

```
Modules/Provider/routes/api.php
  ├── require admin.php      → /api/admin/v1/providers*
  └── require dashboard.php  → /api/provider/v1/*
```

Module `RouteServiceProvider` loads `routes/api.php` under prefix `api`.

### Dashboard controllers

| Controller | Responsibility |
|------------|----------------|
| `ProviderAuthController` | login, logout, me, check-token |
| `ProviderRegistrationController` | register → verify → create-password |
| `ProviderPasswordResetController` | forgot/reset; reset URL `/provider/reset-password?flow_token=...` |
| `ProviderProfileController` | profile + password update |
| `ProviderSocialAuthController` | OAuth redirect; callback via shared user URI |

### Middleware

- Guest routes: `guest:provider_api` — JSON 403 when already authenticated (`RedirectIfAuthenticated` includes `api/provider/*`)
- Authenticated routes: `auth:provider_api`

---

## OAuth flow

1. SPA: `SocialAuthButtons` with `panel="provider"` → `GET /auth/provider/{google|apple}/redirect`
2. `ProviderSocialAuthController@redirect` sets `social_auth_panel = provider`, Socialite redirect
3. Provider configured redirect: `{APP_URL}/auth/user/google/callback` (see `config/services.php`)
4. `UserSocialAuthController@callback` reads session; if panel is `provider`, forwards to `ProviderSocialAuthController@callback`
5. `SocialAuthService::authenticate(..., Provider::class, $allowRegistration)` — `$allowRegistration` passed explicitly (closure capture fix)
6. On success → redirect `/provider/oauth/callback?token=...` (or flow_token for verify/password steps)

---

## Admin CRUD stack

`ProviderController` → `ProvidersService` → `ProviderRepository`

Same entity CRUD pattern as Admin/User modules (not Catalog base).

---

## Frontend (Provider SPA)

```
resources/js/apps/provider/provider-app.js
  → ProviderApp.vue
  → router/provider-index.js (history base: /provider)
  → modules/provider/routes.js
```

| Concern | Implementation |
|---------|----------------|
| Token storage | `localStorage.provider_token` via `stores/providerAuth.js` |
| HTTP client | `api/providerAxios.js` — attaches Bearer + `X-Locale` |
| Layout | `layouts/provider/ProviderLayout.vue` + `ProviderSidebar.vue` (**no AI chat nav**) |
| Header | `composables/useHeader.js` — `route.name.startsWith('provider.')` |
| Guards | `router/middleware/providerAuth.js`, `providerGuest.js` |
| Vite input | `resources/js/apps/provider/provider-app.js` |
| Web shell | `routes/web.php` → `/provider/{any?}` → `provider.blade.php` |

### SPA routes (summary)

| Path | Name | Auth |
|------|------|------|
| `/provider/login` | `provider.login` | guest |
| `/provider/sign-up` | `provider.sign-up` | guest |
| `/provider/verify-email` | `provider.verify-email` | guest |
| `/provider/create-password` | `provider.create-password` | guest |
| `/provider/forgot-password` | `provider.forgot-password` | guest |
| `/provider/reset-password` | `provider.reset-password` | guest |
| `/provider/oauth/callback` | `provider.oauth.callback` | guest |
| `/provider/dashboard` | `provider.dashboard` | auth |
| `/provider/profile` | `provider.profile` | auth |

---

## Service selection UI

- Login/me/check-token responses include `services[]`
- `providerServiceSelection` store persists selected service id to `localStorage` (`provider_selected_service_id`)
- Sidebar links derived from `category.module_name` (mock link subset until per-module routes exist)

---

## Dependencies

- General `CountryController@dropdown` on provider API
- General `ServiceCategoryController` for admin provider modal + tree options
- Shared auth services: `AuthFlowTokenService`, `VerificationCodeService`, `SocialAuthService`
