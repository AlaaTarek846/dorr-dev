# Project Checkpoint

**Last updated:** 2026-09-17  
**Purpose:** Quick orientation for developers and AI assistants.

---

## Current Phase

**Foundation + Admin/User platforms operational.**  
Documentation system established. Public website and permission system not implemented.

---

## Completed Work

### Backend
- Laravel 12 monolith with 4 modules (Admin, User, AI, Provider)
- Shared catalog in `app/.../General/` (Country, Currency, Flag, Language, ServiceCategory, PlatformSetting)
- Sanctum auth with `admin_api` and `user_api` guards
- Standard API response envelope
- Translation-based catalog pattern
- AI gateway with multiple providers
- Provider profiles with service category linkage

### Frontend
- Admin SPA: full catalog CRUD, users, providers, AI settings, platform settings
- User SPA: auth flows, profile, AI chat
- i18n: Arabic + English
- Pinia stores, composable-based CRUD

### Documentation
- Full `docs/` structure (16 files + AI instructions)
- Module docs for Admin, User, AI, Provider, General

---

## Current Work

- Documentation system (this checkpoint created with initial docs)
- **UNKNOWN:** No other active work tracked in repo

---

## Pending Work

| Priority | Item | Status |
|----------|------|--------|
| High | Expand API/feature test coverage | TODO |
| High | Define admin permissions (Spatie) | NEEDS-DECISION |
| Medium | Public website scope | NEEDS-DECISION |
| Medium | Production deployment documentation | TODO |
| Low | Frontend ESLint/Prettier setup | TODO |
| Low | Implement `useAuth` / `usePermission` stubs | Blocked on permissions |
| Low | Centralized frontend API service layer | Optional refactor |

---

## Known Issues

| Issue | Severity | Notes |
|-------|----------|-------|
| Spatie Permission unused | Medium | Roles not enforced |
| Empty composables (`useAuth`, `usePermission`) | Low | Stubs |
| Empty services (`api.js`, `auth.service.js`) | Low | Stubs |
| No frontend tests | Medium | PHPUnit only |
| Module web routes vs SPA | Low | Legacy resource routes may be unused |

---

## Tests Status

| Suite | Status | Count |
|-------|--------|-------|
| PHPUnit | Passing | 7 tests, 15 assertions |
| Frontend | Not configured | 0 |

**Command:** `composer test` or `php artisan test`

---

## Documentation Status

| Area | Status |
|------|--------|
| Core docs (01–16) | Created |
| AI instructions | Created |
| Module docs | Created (5 modules) |
| API spec | Matches current routes |
| Data model | Matches migrations |

**Rule:** Update docs when changing architecture, API, or schema.

---

## Security Status

| Control | Status |
|---------|--------|
| Sanctum token auth | Active |
| Password hashing | Laravel default |
| Validation on Form Requests | Active |
| CSRF on web forms | N/A for token API |
| Rate limiting | **UNKNOWN** — not confirmed on auth routes |
| Permission enforcement | Not active |
| Secrets in repo | `.env.example` only (no secrets) |

---

## Next Step (Recommended)

1. Review `docs/01-PRODUCT-REQUIREMENTS.md` open questions with product owner
2. Decide Spatie Permission strategy
3. Add feature tests for admin login + one catalog CRUD flow
4. Decide public website scope before implementing `modules/website`

---

## Quick Start Commands

```bash
composer setup          # Full install + migrate + npm build
composer dev            # Server + queue + logs + vite
composer test           # Run PHPUnit
php artisan route:list  # Verify API routes
npm run dev             # Vite dev server only
npm run build           # Production frontend build
```
