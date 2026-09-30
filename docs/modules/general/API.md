# General — API

> Full details: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)

## Public General API (`/api/general/v1`)

No authentication. Used by mobile apps and pre-login flows.

| Method | Endpoint |
|--------|----------|
| GET | `/countries/dropdown` |
| GET | `/countries/detect` |
| GET | `/languages/dropdown` |
| GET | `/platform-settings/branding` |
| GET | `/services` |

Controller: `App\Http\Controllers\General\Public\GeneralController`  
Routes: `routes/general.php` (loaded from `routes/api.php`).

`/services` returns the customer-app home services — active top-level service categories with `is_login_dashboard = true`, ordered by `sort_order` — each as `id`, `name` (translated), `module_name`, `image`, `requires_provider`, `has_children`, `children[] {id, name, module_name, image}` (active children only). Managed from the admin dashboard (service categories).

`/countries/dropdown` returns: `id`, `code`, `name`, `dial_code`, `phone_length`, `phone_starts_with`, `is_default`, `flag {id, code}`.

`/countries/detect` returns one country in the same shape: the caller's country by IP (`getCountryCodeByIp()` in `app/Support/helpers.php`), or the default country when it can't be told or **isn't active**. Order: Cloudflare's `CF-IPCountry` header → `ipwho.is` → `ip-api.com` (geoplugin.net went paid-only in 2026 and answers 403). Loopback/LAN IPs are not looked up. The detected code is cached per IP for a day (a failed lookup for 10 minutes); the active check runs on every call. Behind a proxy or tunnel (ngrok, nginx, a load balancer), set `TRUSTED_PROXIES` in `.env` (`127.0.0.1,::1` for ngrok locally, or `*`), or every caller looks like the proxy. The mobile login screen uses it to preselect the country, then falls back to the phone's network/SIM country. **NEEDS-DECISION (production):** the free providers are rate-limited (ip-api.com is non-commercial); use Cloudflare's header or a licensed source (e.g. MaxMind GeoLite2) in production.

## Admin Routes (`/api/admin/v1`)

### Authenticated — Platform Settings

| Method | Endpoint |
|--------|----------|
| GET | `/platform-settings` |
| POST | `/platform-settings` |

### Authenticated — Catalog Standard Pattern

Resources: `flags`, `languages`, `currencies`, `countries`, `service-categories`, `faqs`, `privacy-policies`

| Method | Endpoint |
|--------|----------|
| GET | `/{resource}` |
| POST | `/{resource}` |
| GET | `/{resource}/{id}` |
| PUT/PATCH | `/{resource}/{id}` |
| DELETE | `/{resource}/{id}` |
| POST | `/{resource}/delete-multiple` |
| PATCH | `/{resource}/{id}/status` |
| GET | `/{resource}/dropdown` |

**Exception:** languages has no authenticated dropdown (public dropdown instead).

### Extra

| Method | Endpoint |
|--------|----------|
| POST | `/currencies/sync-exchange-rates` |
| GET | `/service-categories/tree` |
| GET | `/service-categories/tree-options` |
| GET | `/service-categories/leaf-options` |
| GET | `/service-categories/dropdown?parent_id=null` (parents only) |

`/service-categories/dropdown` returns active categories: `id`, `name` (translated), `parent_id`, `module_name`, `image`, `translations {locale, name}`. `image`, `name`, and `module_name` drive the **AdminServiceSelect** header dropdown (frontend-only "General" item first) and per-module sidebar sections (Chat `module_name=chat`, AI `module_name=ai_assistant`).

## User Routes (`/api/user/v1`)

| Method | Endpoint | Auth |
|--------|----------|------|
| GET | `/countries/dropdown` | public (alias → same handler as general) |
| GET | `/countries/detect` | public (alias → same handler as general) |

Prefer **`/api/general/v1/*`** for new clients (mobile, shared catalog).
