# API Specification

**Last updated:** 2026-09-17

> Base URL: relative `/api` (same origin).  
> All documented endpoints **exist in route files** as of documentation date.

---

## Global Conventions

### Headers

| Header | When | Value |
|--------|------|-------|
| `Accept` | All | `application/json` |
| `Content-Type` | JSON bodies | `application/json` |
| `Authorization` | Authenticated routes | `Bearer {token}` |
| `X-Locale` | All admin/v1, user/v1, provider/v1 | `ar` or `en` |
| `Accept-Language` | User/Provider clients also send | locale code |

### Response Envelope (Success)

```json
{
  "success": true,
  "status": "success",
  "code": 200,
  "message": "Human-readable message",
  "data": {},
  "pagination": null
}
```

### Response Envelope (Error)

```json
{
  "success": false,
  "status": "error",
  "code": 400,
  "message": "Error message",
  "data": {},
  "pagination": null,
  "errors": {}
}
```

`errors` present on validation failures (422).

### Pagination

When listing with pagination, `pagination` object includes:
`per_page`, `path`, `total`, `current_page`, `next_page_url`, `prev_page_url`, `last_page`, `has_more_pages`, `from`, `to`

List endpoints accept pagination/search params via `allOrPaginate()` helper (see frontend `crudStructure.js` for typical query usage).

### Authentication Guards

| Guard | Middleware | Audience |
|-------|------------|----------|
| `admin_api` | `auth:admin_api` | Admin token |
| `user_api` | `auth:user_api` | User token |
| `provider_api` | `auth:provider_api` | Provider token |
| `sanctum` | `auth:sanctum` | Root stub route only |

---

## Root API

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/user` | sanctum + locale | Laravel stub (returns authenticated user) |

---

## Admin API — `/api/admin/v1`

Middleware: `locale` on group; `guest:admin_api` or `auth:admin_api` on subgroups.

### Public (no auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/platform-settings/branding` | Platform branding assets |
| GET | `/languages/dropdown` | Active storable languages dropdown |

### Guest

| Method | Endpoint | Body | Description |
|--------|----------|------|-------------|
| POST | `/login` | email, password | Admin login → token |
| POST | `/check-token` | token fields per request | Validate token |

### Authenticated — Auth & Profile

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/me` | Current admin |
| POST | `/logout` | Revoke token |
| POST | `/profile` | Update profile (incl. avatar media) |
| PUT | `/profile/password` | Change password |

### Authenticated — Platform Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/platform-settings` | Full settings |
| POST | `/platform-settings` | Update app_name + media assets |

Validation: `PlatformSettingUpdateRequest` — app_name required; file rules per media collection; `remove_{collection}` flags.

### Authenticated — Admins

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admins` | List |
| POST | `/admins` | Create |
| GET | `/admins/{admin}` | Show |
| PUT/PATCH | `/admins/{admin}` | Update |
| DELETE | `/admins/{admin}` | Delete |
| POST | `/admins/delete-multiple` | Bulk delete `{ ids: [] }` |
| PATCH | `/admins/{admin}/status` | `{ status: bool }` |

### Authenticated — Users (managed by admin)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/users` | List |
| POST | `/users` | Create |
| GET | `/users/{user}` | Show |
| PUT/PATCH | `/users/{user}` | Update |
| DELETE | `/users/{user}` | Delete |
| POST | `/users/delete-multiple` | Bulk delete |
| PATCH | `/users/{user}/status` | Status change |

### Authenticated — Catalog Resources (Standard Pattern)

Applies to: **flags**, **languages**, **currencies**, **countries**, **service-categories**

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/{resource}` | Paginated list |
| POST | `/{resource}` | Create |
| GET | `/{resource}/{id}` | Show |
| PUT/PATCH | `/{resource}/{id}` | Update |
| DELETE | `/{resource}/{id}` | Delete |
| POST | `/{resource}/delete-multiple` | `{ ids: [] }` |
| PATCH | `/{resource}/{id}/status` | `{ status: bool }` |
| GET | `/{resource}/dropdown` | **Not on languages** (languages dropdown is public) |

**Extra endpoints:**

| Resource | Method | Endpoint |
|----------|--------|----------|
| currencies | POST | `/currencies/sync-exchange-rates` |
| service-categories | GET | `/service-categories/tree` |
| service-categories | GET | `/service-categories/tree-options` (PrimeVue TreeSelect format; parents `selectable: false`) |
| service-categories | GET | `/service-categories/leaf-options` |
| service-categories | GET | `/service-categories/dropdown?parent_id=null` (parents only) |

**Controllers:** `App\Http\Controllers\General\{Entity}Controller`  
**Validation:** `App\Http\Requests\General\{Entity}Request`  
**Responses:** `App\Http\Resources\General\{Entity}Resource`

Catalog create/update requires `translations[]` with `locale` + `name` for all storable languages.

**Countries dropdown** (`/countries/dropdown`) returns each active country: `id`, `code`, `name`, `dial_code`, `phone_length`, `phone_starts_with`, `is_default`, `flag {id, code}`. `phone_length` + `phone_starts_with` + `is_default` drive frontend phone placeholder (`prefix*********`), phone validation, and default-country auto-selection in Admin user/provider modals.

### Authenticated — Providers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/providers` | List |
| POST | `/providers` | Create |
| GET | `/providers/{provider}` | Show |
| PUT/PATCH | `/providers/{provider}` | Update |
| DELETE | `/providers/{provider}` | Delete |
| POST | `/providers/delete-multiple` | Bulk delete |
| PATCH | `/providers/{provider}/status` | Status |

### Authenticated — AI Providers `/api/admin/v1/ai-providers`

Middleware: `locale`, `auth:admin_api`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List all providers |
| POST | `/{provider}` | Update provider config |
| POST | `/{provider}/test` | Test connection |
| POST | `/{provider}/set-default` | Set as default |

`{provider}` ∈ `openai`, `anthropic`, `google`, `groq` (AiProviderKey enum).

---

## Provider Portal API — `/api/provider/v1`

Middleware: `locale` on group; `guest:provider_api` or `auth:provider_api` on subgroups.

Module routes: `Modules/Provider/routes/dashboard.php`.

### Guest

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/login` | Provider login → Sanctum token |
| POST | `/check-token` | Token validation |
| POST | `/register` | Start registration → flow_token |
| POST | `/verify-email` | OTP verification |
| POST | `/resend-verification` | Resend OTP |
| POST | `/create-password` | Set password after verification |
| POST | `/forgot-password` | Request reset (email link → `/provider/reset-password`) |
| POST | `/reset-password` | Complete reset |

Guest routes return JSON 403 if already authenticated (`RedirectIfAuthenticated` + `api/provider/*`).

### Authenticated

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/countries/dropdown` | Countries dropdown (shared General controller) |
| GET | `/me` | Current provider |
| POST | `/logout` | Revoke token |
| POST | `/profile` | Update profile |
| PUT | `/profile/password` | Change password |

**Provider payload** (login, check-token, me) includes `services[]` via `ProviderServiceResource` — used by provider header service dropdown and sidebar.

**Not available:** `/api/provider/v1/ai-chat/*` (User-only).

---

## User API — `/api/user/v1`

Middleware: `locale` on group.

### Guest

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/login` | User login |
| POST | `/check-token` | Token validation |
| POST | `/register` | Start registration |
| POST | `/verify-email` | OTP verification |
| POST | `/resend-verification` | Resend OTP |
| POST | `/create-password` | Set password after verification |
| POST | `/forgot-password` | Request reset |
| POST | `/reset-password` | Complete reset |

### Authenticated

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/countries/dropdown` | Countries dropdown |
| GET | `/me` | Current user |
| POST | `/logout` | Logout |
| POST | `/profile` | Update profile |
| PUT | `/profile/password` | Change password |

### Authenticated — AI Chat `/api/user/v1/ai-chat`

Middleware: `locale`, `auth:user_api`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | AI availability |
| GET | `/conversations` | List conversations |
| POST | `/conversations` | Create conversation |
| GET | `/conversations/{conversation}` | Show + messages |
| DELETE | `/conversations/{conversation}` | Delete |
| POST | `/conversations/{conversation}/messages` | Send message |

---

## Web Routes (Non-API)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/` | Welcome page |
| GET | `/admin/{any?}` | Admin SPA shell |
| GET | `/user/{any?}` | User SPA shell |
| GET | `/provider/{any?}` | Provider SPA shell |
| GET | `/auth/user/{provider}/redirect` | User OAuth redirect (google, apple) |
| GET | `/auth/user/{provider}/callback` | **Shared OAuth callback** (Google/Apple redirect URI); session `social_auth_panel` routes to User or Provider handler |
| GET | `/auth/provider/{provider}/redirect` | Provider OAuth redirect (sets `social_auth_panel=provider`) |
| GET | `/auth/provider/{provider}/callback` | Provider OAuth callback (alternate; production uses user callback URI) |

OAuth SPA landing routes: `/user/oauth/callback`, `/provider/oauth/callback`.

---

## Public Endpoints

**PLANNED** — No `/api/public/v1/*` routes exist.

Partial public data today:
- `GET /api/admin/v1/platform-settings/branding` (no auth)
- `GET /api/admin/v1/languages/dropdown` (no auth)

---

## HTTP Status Codes (Observed)

| Code | Usage |
|------|-------|
| 200 | Success |
| 201 | Created (`ApiResponse::created`) |
| 204 | Deleted (`ApiResponse::noContent`) |
| 401 | Unauthenticated |
| 404 | Not found (API JSON) |
| 422 | Validation error |
| 409 | Conflict (delete blocked) |

---

## Permissions

**No endpoint-level permission names enforced.**  
Any valid admin token accesses all admin routes; any valid user token accesses all user routes; any valid provider token accesses all provider routes.

**NEEDS-DECISION:** Future permission matrix.

---

## Module Documentation

Detailed module APIs: see `docs/modules/*/API.md`.
