# Roadmap

> Based on implemented features and confirmed gaps. No fictional items.

---

## Completed

- [x] Laravel 12 + Vue 3 dual SPA foundation
- [x] nwidart module structure (Admin, User, AI, Provider)
- [x] Sanctum authentication (admin + user guards)
- [x] Shared catalog: flags, languages, currencies, countries, service categories
- [x] General/ namespace refactor for shared catalog code
- [x] Platform settings + branding
- [x] Admin dashboard (catalog CRUD, users, providers, AI settings)
- [x] User registration with email OTP + password setup
- [x] User social login (Google, Apple)
- [x] User AI chat
- [x] Admin AI provider configuration
- [x] Provider management (admin)
- [x] i18n (ar/en) frontend + API locale middleware
- [x] Spatie Media Library integration
- [x] Exchange rate sync for currencies
- [x] Documentation system (this docs/ folder)

---

## Current

- [ ] Documentation maintenance — keep docs synced with code
- [ ] Expand test coverage beyond exception renderer + example tests

**UNKNOWN:** Active sprint items not tracked in repository.

---

## Next

Items inferred from architecture gaps — prioritize with team:

| Item | Status | Notes |
|------|--------|-------|
| Public website module | **NEEDS-DECISION** | No frontend/API exists |
| Public read-only API (`/api/public/v1`) | **NEEDS-DECISION** | Reuse General controllers |
| Spatie Permission rollout | **NEEDS-DECISION** | Installed, not used in routes |
| Frontend `usePermission` implementation | Blocked on permissions decision |
| Centralized frontend API services | Stubs exist; optional refactor |
| API integration tests for catalog + auth | Testing gap |

---

## Planned

**NEEDS-DECISION** — no product backlog in repo.

Suggested candidates (require approval):
- Provider self-service portal
- Admin role hierarchy
- Website/marketing pages
- Email/SMS notification expansion
- Queue-based jobs for heavy tasks (AI, exchange rates)

---

## Future

**NEEDS-DECISION**

Questions for product owner:
1. Multi-tenancy?
2. Mobile apps consuming same API?
3. Payment/billing integration?
4. Real-time features (WebSockets)?

---

## How to Update This Roadmap

1. Confirm feature status in codebase
2. Move items between sections with date note in CHANGELOG
3. Never add items without **NEEDS-DECISION** label if not approved
