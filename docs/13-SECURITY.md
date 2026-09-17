# Security

> Do not store real credentials in documentation or code.

---

## Authentication

| Mechanism | Implementation |
|-----------|----------------|
| Admin API | Laravel Sanctum personal access tokens |
| User API | Laravel Sanctum personal access tokens |
| Guard separation | `admin_api` vs `user_api` |
| Password storage | Laravel hashed cast |
| Social login | Google + Apple via Socialite (web redirect flow) |
| Email verification | OTP via `VerificationCode` polymorphic model |
| Flow tokens | `AuthFlowTokenService` with configurable expiry |

### Token Handling (Frontend)

- Admin token: `localStorage.admin_token`
- User token: `localStorage.user_token`
- Sent as `Authorization: Bearer {token}`

**Risk note:** localStorage tokens are vulnerable to XSS. Ensure no untrusted script injection in SPAs.

---

## Authorization

| Control | Status |
|---------|--------|
| Route authentication (`auth:*`) | **Active** |
| Spatie roles/permissions | **Installed, not enforced on routes** |
| Laravel Policies | **None implemented** |
| Resource ownership checks | AI conversations scoped to user in service layer (verify in module code when modifying) |

**NEEDS-DECISION:** Admin role hierarchy and permission matrix.

---

## CSRF

- API routes use token authentication (not cookie CSRF for SPA JSON calls)
- Web OAuth routes use standard web middleware stack
- `bootstrap.js` sets `X-Requested-With` on default axios instance

---

## Validation

- All API input validated via Form Request classes (module + General)
- Catalog validation centralized in `HasCatalogRules`
- File upload validation: mime types + max sizes in requests (e.g., platform settings)

---

## Rate Limiting

**UNKNOWN** — dedicated throttle middleware not confirmed on auth endpoints during analysis.

**TODO:** Review and add rate limiting on:
- Login
- OTP resend
- Password reset
- AI message sending

---

## API Security

| Practice | Status |
|----------|--------|
| JSON error responses (no stack in production) | Yes — debug only when `APP_DEBUG=true` and `debug=1` |
| Mass assignment protection | `$fillable` on models |
| SQL injection | Eloquent/query builder (no raw user SQL observed) |
| Guard mismatch protection | Separate guards per audience |

---

## XSS Prevention

- Vue escapes template output by default
- API returns JSON (not HTML fragments)
- Blade views for SPA shells — minimize unescaped user content

---

## File Upload Security

- Spatie Media Library with validated mime types in Form Requests
- Storage disk configurable (`FILESYSTEM_DISK`)
- Media path generator: `ModelFolderPathGenerator`

**Rules when adding uploads:**
- Validate mime type and size in Form Request
- Never trust client filename
- Do not expose storage paths that bypass authorization

---

## Sensitive Data

### Stored
- Passwords (hashed)
- API keys for AI providers (in `ai_providers` table)
- OAuth tokens (in `social_accounts`)

### Environment Variables (from `.env.example`)

Never commit `.env`. Key variables:

```
APP_KEY
DB_PASSWORD
MAIL_PASSWORD
AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY
GOOGLE_CLIENT_SECRET
APPLE_CLIENT_SECRET / APPLE_PRIVATE_KEY
AI_GROQ_SEED_API_KEY
AUTH_OTP_* 
```

---

## Permissions Package (Spatie)

- Tables migrated
- Middleware aliases: `role`, `permission`, `role_or_permission`
- User model uses `HasRoles`
- **Not applied to routes** — all authenticated admins have full admin API access today

---

## Security Headers

**UNKNOWN** — no custom security headers middleware confirmed.

**TODO:** Review production headers (CSP, HSTS, X-Frame-Options) at web server level.

---

## Reporting Vulnerabilities

**NEEDS-DECISION** — no project-specific security contact defined.  
Do not use Laravel framework's default security email for this project.

---

## Security Checklist for New Features

- [ ] Input validated via Form Request
- [ ] Authorization guard correct (`admin_api` vs `user_api`)
- [ ] Permission check (when Spatie enabled)
- [ ] File uploads validated
- [ ] No secrets in code or docs
- [ ] User can only access own resources (where applicable)
- [ ] Rate limiting considered for auth/sensitive endpoints
