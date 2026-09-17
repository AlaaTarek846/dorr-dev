# Product Specification

> Describes **how the product currently works**, based on implemented code only.

---

## System Overview

```
Browser
  ├── /admin/*  → Admin Vue SPA → /api/admin/v1/*
  ├── /user/*   → User Vue SPA  → /api/user/v1/*
  └── /         → welcome.blade.php (static)
```

OAuth web flow: `/auth/user/{google|apple}/redirect|callback` → User SPA OAuth callback route.

---

## User Flows

### Admin Login Flow

1. Admin opens `/admin/login`
2. Submits email + password → `POST /api/admin/v1/login`
3. On success: token stored in `localStorage.admin_token`
4. Redirect to `/admin/dashboard`
5. Subsequent requests send `Authorization: Bearer {token}` + `X-Locale`

### Admin Catalog Management Flow

1. Admin navigates to catalog page (e.g., `/admin/countries`)
2. Frontend loads list via `GET /api/admin/v1/countries` (paginated/search/filter)
3. Create/Edit opens modal; submits `POST` or `PUT/PATCH`
4. Translations required for all storable language locales
5. Status toggle via `PATCH .../status`
6. Bulk delete via `POST .../delete-multiple`

### User Registration Flow

1. User opens `/user/sign-up`
2. Submits registration → `POST /api/user/v1/register`
3. Receives flow token; redirected to `/user/verify-email`
4. Enters OTP → `POST /api/user/v1/verify-email`
5. Creates password → `POST /api/user/v1/create-password`
6. Can login → `POST /api/user/v1/login`

### User Social Login Flow

1. User clicks Google/Apple on login/sign-up
2. Browser redirects to `/auth/user/{provider}/redirect`
3. Provider callback → `/auth/user/{provider}/callback`
4. User redirected to `/user/oauth/callback` in SPA
5. Token stored; user authenticated

### User AI Chat Flow

1. Authenticated user opens `/user/chat`
2. Checks AI availability → `GET /api/user/v1/ai-chat/status`
3. Lists conversations → `GET /api/user/v1/ai-chat/conversations`
4. Creates conversation or opens existing
5. Sends message → `POST .../conversations/{id}/messages`
6. AI response stored and displayed

### Admin AI Provider Configuration

1. Admin opens `/admin/ai-settings`
2. Lists providers → `GET /api/admin/v1/ai-providers`
3. Updates API keys/settings → `POST /api/admin/v1/ai-providers/{provider}`
4. Tests connection → `POST .../test`
5. Sets default → `POST .../set-default`

---

## Feature Behavior

### Platform Branding

- `GET /api/admin/v1/platform-settings/branding` — **public within admin API group** (no auth)
- Used by admin/user Blade views to inject `window.__PLATFORM_BRANDING__`
- Includes app name, logo URLs, favicon URLs

### Language / Locale

- Admin can configure languages with `stores_translation`, `is_default_dashboard`, `is_default_website`, direction (RTL/LTR)
- Disabling `stores_translation` purges catalog translations for that locale (LanguageRepository)
- Frontend syncs document direction on locale change

### Currency Exchange Rates

- Listing/viewing currencies triggers stale check via `ExchangeRateService`
- Admin can force sync: `POST /api/admin/v1/currencies/sync-exchange-rates`
- Provider: `open_er_api` (configurable)

### Service Categories

- Hierarchical tree structure (`parent_id`)
- `tree` endpoint returns nested structure
- `leaf-options` returns leaf categories for selection UIs
- `requires_provider` flag indicates category needs a provider
- Image upload via media collection `image`

### Delete Protection

- Repositories use `deleteBlockRelations` and FK detection
- Returns conflict error when delete blocked (via `ConflictException` / `ApiExceptionRenderer`)

---

## Validation Rules (Summary)

### Catalog entities (Flags, Countries, Languages, Currencies, Service Categories)

- Shared patterns in `App\Http\Requests\Concerns\HasCatalogRules`
- Translations array required; minimum count = storable language locales
- Status change: `{ status: boolean }`
- Bulk delete: `{ ids: array }` with existence validation

### Country-specific

- `code` unique, `dial_code` regex, `phone_starts_with`, `phone_length`
- `flag_id`, `currency_id` required FKs

### Platform settings

- `app_name` required
- Media collections: logo, logo_dark, favicon variants, apple_touch_icon, web_manifest
- Remove flags: `remove_{collection}` boolean per asset

### User registration/auth

- Validated in module Form Requests (`UserRegisterRequest`, `VerifyEmailRequest`, etc.)
- OTP length/expiry from env: `AUTH_OTP_*`, `AUTH_FLOW_TOKEN_EXPIRY_MINUTES`

---

## User Permissions

| Area | Current Behavior |
|------|------------------|
| Admin endpoints | Any authenticated admin (`auth:admin_api`) |
| User endpoints | Any authenticated user (`auth:user_api`) |
| Spatie roles/permissions | **Not enforced on routes** |
| Policy classes | **None implemented** |

**NEEDS-DECISION:** Granular admin permissions.

---

## Error Scenarios

| Scenario | Behavior |
|----------|----------|
| Validation failure | 422 JSON with `errors` object |
| Unauthenticated API | 401 JSON via Sanctum |
| Wrong guard / guest route with token | Redirect/guest middleware behavior |
| Missing API route | Localized JSON 404 (`ApiExceptionRendererTest`) |
| Delete blocked | Conflict response via `ConflictException` |
| AI provider misconfiguration | Error from AI gateway (see AI module) |

---

## Edge Cases

1. **Language locale removed from storable list** — existing translations for that locale are purged from catalog translation tables
2. **User password nullable** — supports registration flow before password creation
3. **OAuth users** — linked via polymorphic `social_accounts`
4. **Default AI provider** — only one default at a time (`is_default` on ai_providers)
5. **Admin vs User token isolation** — separate localStorage keys and axios clients

---

## Expected System Behavior

- All API responses follow `ApiResponse` envelope (success/error)
- Locale middleware applies to admin/v1 and user/v1 route groups
- Pagination metadata included when listing endpoints use `allOrPaginate()`
- Media files served from `/storage/...` (Spatie Media Library)
