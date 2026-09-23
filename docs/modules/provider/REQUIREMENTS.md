# Provider — Requirements

**Last updated:** 2026-09-20

---

## Implemented — Admin

- Admin CRUD for provider profiles
- Status management + bulk delete / restore / force delete
- Link providers to country
- Link providers to service categories via `provider_services`
- Media support on provider model
- Admin UI: `/admin/providers` with CRUD composable pattern

---

## Implemented — Provider portal (self-service)

- Provider dashboard SPA at `/provider`
- Self-registration: register → email OTP → create password → login
- Login / logout / check-token / me
- Profile update + password change
- Forgot / reset password (email link to `/provider/reset-password`)
- Google / Apple social login (shared OAuth callback URI with User panel)
- Dashboard + profile pages
- Service category header dropdown + sidebar (from assigned `provider_services`)
- Arabic + English UI (`provider_dashboard.*` i18n)

**Not included:** AI chat (User-only feature)

---

## Business rules (from code)

- Providers table: `providers`
- `ProviderService` links provider to `ServiceCategory`
- Registration creates provider with `UserStatus::Active` immediately (no admin approval gate in code)
- Sanctum token name on login: `provider-api`
- One social account (`provider` + `provider_id`) → one authenticatable model

---

## NEEDS-DECISION

| Topic | Notes |
|-------|-------|
| Admin approval after provider self-registration | Currently auto-`Active`; product may want pending/review state |
| Provider visibility on public website | Not implemented |
| Per-`module_name` real dashboard modules | Sidebar uses placeholder links today |
| Permission matrix for provider vs admin | Spatie unused |
