# Provider — Testing

**Last updated:** 2026-09-20

---

## Automated coverage

| Area | Status |
|------|--------|
| Provider admin CRUD | None |
| Provider portal auth | None |
| PHPUnit total | 7 tests (project-wide) |

Recommended locations: `Modules/Provider/tests/` or `tests/Feature/Provider/`

---

## Manual checklist — Provider portal auth

Prerequisites: migrated DB, mail driver configured (or log driver), optional Google OAuth env vars, `npm run build` or `npm run dev` for SPA assets.

Verify Blade shell: open `/provider/login`, console `window.__DASHBOARD_THEME__` and Network requests to `/dashboard/themes/{path}/assets/...`.

### Email registration

- [ ] `POST /api/provider/v1/register` or SPA `/provider/sign-up` — receive flow_token
- [ ] `/provider/verify-email` — OTP accepts code
- [ ] `/provider/create-password` — password set, can login
- [ ] `/provider/login` — returns token + provider payload with `services[]` if seeded

### Session / profile

- [ ] Token stored as `provider_token` in localStorage
- [ ] `/provider/dashboard` loads when authenticated
- [ ] `GET /api/provider/v1/me` returns current provider
- [ ] `/provider/profile` — update profile + change password
- [ ] `POST /api/provider/v1/logout` clears session client-side

### Password reset

- [ ] `/provider/forgot-password` sends reset link
- [ ] Email link opens `/provider/reset-password?flow_token=...`
- [ ] `POST /api/provider/v1/reset-password` completes reset

### OAuth (optional)

- [ ] Login/Sign-up Google button → `/auth/provider/google/redirect`
- [ ] Callback via `/auth/user/google/callback` lands on `/provider/oauth/callback`
- [ ] Success stores token; needs_verification / needs_password flows redirect correctly

### Guest guard

- [ ] Authenticated provider calling guest endpoints (e.g. login) returns JSON 403

### Negative

- [ ] Provider portal has **no** AI chat route or API
- [ ] User token does not work on `auth:provider_api` routes

---

## Manual checklist — Admin providers

- [ ] `/admin/providers` CRUD
- [ ] Assign service categories; verify `services[]` on provider login/me
- [ ] Status change + bulk delete / restore / force delete

---

## Commands

```bash
composer test
php artisan route:list --path=provider
npm run build   # after frontend changes
```
