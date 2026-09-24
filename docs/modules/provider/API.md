# Provider — API

**Last updated:** 2026-09-20

---

## Admin API — `/api/admin/v1`

Middleware: `locale`, `auth:admin_api`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/providers` | List providers |
| POST | `/providers` | Create provider |
| GET | `/providers/{provider}` | Show provider |
| PUT/PATCH | `/providers/{provider}` | Update provider |
| DELETE | `/providers/{provider}` | Soft delete |
| POST | `/providers/delete-multiple` | Bulk soft delete |
| POST | `/providers/{provider}/restore` | Restore from trash |
| DELETE | `/providers/{provider}/force` | Force delete |
| PATCH | `/providers/{provider}/status` | Change status |

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)

---

## Provider portal API — `/api/provider/v1`

Middleware: `locale` on group; `guest:provider_api` or `auth:provider_api` on subgroups.

### Guest (no token)

| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| POST | `/login` | `ProviderAuthController@login` | Email/password login |
| POST | `/check-token` | `ProviderAuthController@checkToken` | Validate Sanctum token |
| POST | `/register` | `ProviderRegistrationController@register` | Start registration |
| POST | `/verify-email` | `ProviderRegistrationController@verifyEmail` | OTP verification |
| POST | `/resend-verification` | `ProviderRegistrationController@resendVerification` | Resend OTP |
| POST | `/create-password` | `ProviderRegistrationController@createPassword` | Set password after verification |
| POST | `/forgot-password` | `ProviderPasswordResetController@sendResetLink` | Request reset email |
| POST | `/reset-password` | `ProviderPasswordResetController@resetPassword` | Complete reset |

### Authenticated (`auth:provider_api`)

| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/countries/dropdown` | `CountryController@dropdown` | Shared catalog dropdown |
| GET | `/me` | `ProviderAuthController@me` | Current provider |
| POST | `/logout` | `ProviderAuthController@logout` | Revoke token |
| POST | `/profile` | `ProviderProfileController@update` | Update profile |
| PUT | `/profile/password` | `ProviderProfileController@updatePassword` | Change password |

### Provider payload (`login`, `check-token`, `me`)

Includes `services[]` — each row from `provider_services` with nested `category` (`id`, `module_name`, `name`, `requires_provider`, `image`, `translations`). Consumed by provider header service dropdown and sidebar (`ProviderServiceResource`).

---

## OAuth (web, not under `/api`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/auth/provider/{google\|apple}/redirect` | Start OAuth; sets `session('social_auth_panel') = 'provider'` |
| GET | `/auth/user/{google\|apple}/callback` | **Shared redirect URI** (Google/Apple console); if session panel is `provider`, delegates to `ProviderSocialAuthController@callback` |
| GET | `/auth/provider/{google\|apple}/callback` | Alternate callback route (same controller); production OAuth uses user callback URI per `config/services.php` |

Successful OAuth redirects to `/provider/oauth/callback?status=...` (SPA route).

**Constraint:** One Google/Apple account maps to one `social_accounts` row (`provider`, `provider_id` unique) → one authenticatable in the system.

---

## Not implemented

| Item | Status |
|------|--------|
| `/api/provider/v1/ai-chat/*` | Does not exist |
| Public provider listing API | **NEEDS-DECISION** |
