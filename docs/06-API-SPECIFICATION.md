# API Specification

**Last updated:** 2026-09-20

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

Country create/update accepts optional `service_ids[]` (leaf `service_categories` except admin-only modules). Omitted on update keeps the current assignment; `[]` clears it. The public `GET /api/general/v1/services` list is not filtered by this pivot.

### Authenticated — Interface Translations `/api/admin/v1/languages/{language}/translations`

Translation files for new interface languages (e.g. `fr`). `ar` / `en` are the bundled sources and are
rejected (`422 translations_source_locale`). `en` is the base and fallback. Files are JSON stored through
Spatie Media Library (`translation_files` holds metadata only). Import never publishes.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| GET | `/` | `languages.view` | Groups per platform with status, key counts, missing, publish state |
| GET | `/{platform}/{group}/export?format=csv\|json&mode=all\|missing` | `languages.view` | Download a template/working copy (CSV columns: `key,en,ar,translation`) |
| POST | `/{platform}/{group}/validate` | `languages.update` | Dry run: `file` (json/csv) → report |
| POST | `/{platform}/{group}/import` | `languages.update` | Validate and save as draft (missing keys allowed, extra keys / placeholder changes rejected) |
| POST | `/{platform}/{group}/publish` | `languages.update` | Publish the pending draft (version +1) |
| DELETE | `/{platform}/{group}/draft` | `languages.update` | Discard the pending draft |
| GET | `/android/export?source=published\|draft` | `languages.view` | ZIP with `values-{qualifier}/{group}.xml` (generated, never written to the Android project) |

Platforms / groups: `backend` (`api, validation, notifications, chat, wallet, sms, ai, provider`), `vue` (`messages`),
`android` (`strings, chat_strings, wallet_strings`).

**Countries dropdown** (`/countries/dropdown`) returns each active country: `id`, `code`, `name`, `dial_code`, `phone_length`, `phone_starts_with`, `is_default`, `flag {id, code}`. `phone_length` + `phone_starts_with` + `is_default` drive frontend phone placeholder (`prefix*********`), phone validation, and default-country auto-selection in Admin user/provider modals.

### Authenticated — Providers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/providers` | List |
| POST | `/providers` | Create |
| GET | `/providers/{provider}` | Show |
| PUT/PATCH | `/providers/{provider}` | Update |
| DELETE | `/providers/{provider}` | Soft delete |
| POST | `/providers/delete-multiple` | Bulk soft delete |
| POST | `/providers/{provider}/restore` | Restore from trash |
| DELETE | `/providers/{provider}/force` | Force delete |
| PATCH | `/providers/{provider}/status` | Status (`UserStatus` enum) |

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

Route files: `Modules/Provider/routes/api.php` requires `admin.php` (admin CRUD) and `dashboard.php` (portal API below).

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

### Provider SPA routes (Vue Router, base `/provider`)

| Flow | SPA path | API (typical) |
|------|----------|---------------|
| Login | `/provider/login` | POST `/login` |
| Sign-up | `/provider/sign-up` | POST `/register` |
| Verify email | `/provider/verify-email` | POST `/verify-email`, `/resend-verification` |
| Create password | `/provider/create-password` | POST `/create-password` |
| Forgot / reset | `/provider/forgot-password`, `/provider/reset-password` | POST `/forgot-password`, `/reset-password` |
| OAuth landing | `/provider/oauth/callback` | Web OAuth → token in query |
| Dashboard | `/provider/dashboard` | — (auth middleware) |
| Profile | `/provider/profile` | GET `/me`, POST `/profile`, PUT `/profile/password` |

OAuth start: `GET /auth/provider/{google|apple}/redirect` (sets `social_auth_panel=provider`). Google/Apple redirect URI in console: `{APP_URL}/auth/user/{provider}/callback`.

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

## Mobile API — `/api/mobile/v1`

Mobile-only endpoints for the Android app. Guard: `user_api` (Sanctum, `users`
provider). Combined login/register by phone: `POST /auth/otp` creates the user if
the phone does not exist yet, then sends a fixed demo OTP
(`config('auth_flow.phone_otp_fixed')`, default `123456`) stored in
`verification_codes` via `App\Traits\SendsPhoneOtp`. Authenticated routes require
`ensure-phone-verified` — i.e. `users.phone_verified_at` must be non-null.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/auth/otp` | `guest:user_api` | Request OTP (login or auto-register) |
| POST | `/auth/verify` | `guest:user_api` | Verify OTP → marks phone verified, issues bearer token |
| POST | `/auth/resend` | `guest:user_api` | Resend OTP (cooldown enforced) |
| GET | `/auth/me` | `auth:user_api` + `ensure-phone-verified` | Current user |
| POST | `/auth/logout` | `auth:user_api` + `ensure-phone-verified` | Logout, revoke token. Optional `player_id`: that phone stops getting pushes |
| POST | `/profile/phone/request` | `auth:user_api` + `ensure-phone-verified` | Change phone step 1: validate new number, cache it, send OTP to it |
| POST | `/profile/phone/confirm` | `auth:user_api` + `ensure-phone-verified` | Change phone step 2: verify `code` → swap number, re-mark verified |
| PUT | `/profile/identity` | `auth:user_api` + `ensure-phone-verified` | Update `name` + `gender` (`male`/`female`) directly |
| POST | `/profile/avatar` | `auth:user_api` + `ensure-phone-verified` | Replace avatar (`avatar`: jpeg/jpg/png/webp ≤ 2MB, multipart) |
| DELETE | `/profile/avatar` | `auth:user_api` + `ensure-phone-verified` | Remove avatar (idempotent); returns `UserResource` with `avatar: null` |
| GET | `/addresses?search=` | `auth:user_api` + `ensure-phone-verified` | Own addresses, newest first, paginated (`page`/`per_page`, `all=1` for all) |
| POST | `/addresses` | `auth:user_api` + `ensure-phone-verified` | Create address (201) |
| GET | `/addresses/{id}` | `auth:user_api` + `ensure-phone-verified` | Single own address (404 for others') |
| PUT/PATCH | `/addresses/{id}` | `auth:user_api` + `ensure-phone-verified` | Update own address |
| DELETE | `/addresses/{id}` | `auth:user_api` + `ensure-phone-verified` | Soft delete |
| PATCH | `/addresses/{id}/set-default` | `auth:user_api` + `ensure-phone-verified` | Pin/unpin default (`is_default: bool`) |
| POST | `/profile/email/request` | `auth:user_api` + `ensure-phone-verified` | Change email step 1: validate, cache, mail OTP to the new address |
| POST | `/profile/email/confirm` | `auth:user_api` + `ensure-phone-verified` | Change email step 2: verify `code` → swap address, mark verified |
| GET | `/support-tickets` | `auth:user_api` + `ensure-phone-verified` | Own tickets, latest activity first, paginated (`page`/`per_page`, optional `status`). `{ id, title, body, image_url, status, accepts_replies, last_message, last_message_at, created_at }` |
| POST | `/support-tickets` | `auth:user_api` + `ensure-phone-verified` | Open a ticket (`title`, `body`, optional `image` jpeg/jpg/png/webp ≤ 4MB). What was written becomes the first message. 201 |
| GET | `/support-tickets/{id}` | `auth:user_api` + `ensure-phone-verified` | One own ticket (404 for someone else's) |
| PATCH | `/support-tickets/{id}/status` | `auth:user_api` + `ensure-phone-verified` | `status`: the customer can only `closed` an open / reopened ticket, or `reopened` a resolved / closed one (422 otherwise) |
| GET | `/support-tickets/{id}/messages` | `auth:user_api` + `ensure-phone-verified` | The conversation, paginated; `order=desc` returns the newest page first. `{ id, ticket_id, sender: user|support, body, image_url, agent_name, created_at }` |
| POST | `/support-tickets/{id}/messages` | `auth:user_api` + `ensure-phone-verified`, `throttle:30,1` | Write in the ticket: `body` and/or `image`. 422 while the ticket is resolved / closed |
| GET | `/ratings/mine` | `auth:user_api` + `ensure-phone-verified` | `{ rated, rating }` for the app (optional `rateable_type` `service`/`provider` + `rateable_id`) |
| POST | `/ratings` | `auth:user_api` + `ensure-phone-verified`, `throttle:10,1` | Save a rating (`stars` 1–5 in 0.25 steps, optional `comment` ≤ 500, optional `rateable_*`). 201 `{ id, stars, comment, type, prompt_store_review, ... }`; duplicate → 422 |

Payloads: `dial_code` (e.g. `+966`) + `phone` (local digits); verify also sends
`code`. User matched/stored by full phone `+<dial><phone>`.

Phone validation is country-aware: the matching country is resolved by
`dial_code`, then `phone` must respect its `phone_length` (exact digit count) and
`phone_starts_with` prefix. Errors (localized) return under `dial_code`
(`phone_invalid_country`) or `phone` (`phone_invalid_length`,
`phone_invalid_start`).

Responses:
- OTP/verify/resend per `docs/modules/user/API.md`.

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
- `GET /api/general/v1/translations/languages` (no auth) — interface languages: `ar`, `en` plus active languages with a published Vue file (`code`, `name`, `direction`, `version`)
- `GET /api/general/v1/translations/{code}/vue` (no auth) — published Vue messages for a non-bundled locale (`ETag`); `404` when not published
- `GET /api/general/v1/translations/languages?platform=android` (no auth) — Android app languages: `ar`, `en` (bundled in the APK, always listed, `android_version: null`) plus active languages whose Android `strings` group is published. Fields: `id`, `code`, `name`, `direction`, `flag {id, code}`, `android_version`. Without `platform` (or any other value) the response is the Vue list above, unchanged
- `GET /api/general/v1/translations/{code}/android` (no auth) — published Android strings of a non-bundled locale, every published group (`strings`, `chat_strings`, `wallet_strings`) merged into one flat map: `{code, direction, version, strings}`. `strings` values are text or `{quantity: text}` for plurals (decoded, printf placeholders such as `%1$s`). `version` = `sha1("strings:N|chat_strings:N|wallet_strings:N")` of the published versions (0 for unpublished groups), so it changes on every re-publish and equals `android_version` in the list. Headers: `ETag: "android-{code}-{version}"`, `Cache-Control: no-cache`; `If-None-Match` with the current ETag answers `304`. `404` (`translations_not_published`) when `strings` is not published, the language is disabled, or the code is `ar` / `en`

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

- **Chat:** `/api/mobile/v1/chat/*` (guard `user_api`) and `/api/admin/v1/chat-settings`. See [modules/chat/API.md](modules/chat/API.md).
