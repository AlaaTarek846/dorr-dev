# AI Instructions

**Mandatory workflow for Cursor, Claude, ChatGPT, Antigravity, and all AI coding assistants working on Dorr.**

---

## STEP 1 — READ

Before changing any code, read:

1. [README.md](../README.md)
2. This file (`AI-INSTRUCTIONS.md`)
3. [11-CHECKPOINT.md](./11-CHECKPOINT.md) — current project state
4. [04-ARCHITECTURE.md](./04-ARCHITECTURE.md)
5. [16-STYLEGUIDE.md](./16-STYLEGUIDE.md)
6. Relevant module doc: `docs/modules/{module}/README.md`
7. Existing code for the feature area

---

## STEP 2 — UNDERSTAND

Inspect the actual implementation:

- Routes (`php artisan route:list`, module route files)
- Controller → Service → Repository chain
- Form Requests and Resources
- Models and migrations
- Frontend: composables, stores, views
- Tests

**Never assume.** The codebase is the source of truth.

---

## STEP 3 — ANALYZE

Identify impact:

| Area | Question |
|------|----------|
| Files | What files change? |
| Modules | Admin, User, AI, Provider, or General? |
| Database | Migration needed? |
| API | New/changed endpoints? |
| Frontend | Admin SPA, User SPA, or both? |
| Permissions | Spatie impact? (currently unused on routes) |
| Tests | What tests to add/update? |
| Docs | Which docs to update? |

---

## STEP 4 — PLAN

For non-trivial changes, explain before implementing:

- Proposed approach
- Files to create/modify
- Database/API/frontend changes
- Test plan
- Documentation updates

**Do not make unrelated changes.**

---

## STEP 5 — IMPLEMENT

Follow:

- [16-STYLEGUIDE.md](./16-STYLEGUIDE.md)
- [07-IMPLEMENTATION-PLAN.md](./07-IMPLEMENTATION-PLAN.md)
- Existing patterns (copy catalog entity pattern for new catalog entities)

### Backend Pattern (Catalog)

```
app/Http/Controllers/General/{Entity}Controller.php  → extends CatalogController
app/Services/General/{Entity}Service.php             → extends CatalogService
app/Repositories/General/{Entity}Repository.php      → extends TranslatableRepository
app/Http/Requests/General/{Entity}Request.php        → uses HasCatalogRules
app/Http/Resources/General/{Entity}Resource.php      → uses FormatsTranslations
routes/admin.php                                     → General catalog routes (Admin API)
Modules/Admin/routes/admin.php                       → requires routes/admin.php + admin auth/admins CRUD
Modules/User/routes/admin.php                        → users CRUD for Admin API (auth: admin_api)
Modules/User/routes/dashboard.php                    → user SPA API (auth, profile, countries/dropdown)
Modules/Provider/routes/admin.php                    → providers CRUD for Admin API (auth: admin_api)
```

### Frontend Pattern (Admin CRUD)

```
resources/js/composables/use{Entities}.js
resources/js/stores/{entities}.js
resources/js/modules/admin/views/{entity}/index.vue
resources/js/modules/admin/views/{entity}/ModalCreateAndUpdate.vue
resources/js/modules/admin/routes.js
```

Reuse `crudStructure.js`, `useValidation.js`, `useCatalogTranslations.js`.

---

## STEP 6 — TEST

Run:

```bash
composer test
npm run build          # if frontend changed
./vendor/bin/pint --test  # if PHP changed
```

Fix failures caused by your changes. Do not ignore failing tests.

---

## STEP 7 — REVIEW

Self-review checklist:

- [ ] Matches architecture (General vs Module separation)
- [ ] No duplicate logic
- [ ] Correct auth guard
- [ ] Validation on Form Request
- [ ] ApiResponse envelope used
- [ ] No secrets in code
- [ ] No unrelated file changes
- [ ] i18n keys added (ar + en) if UI strings added

---

## STEP 8 — DOCUMENT

Update when behavior/architecture changes:

- [06-API-SPECIFICATION.md](./06-API-SPECIFICATION.md)
- [05-DATA-MODEL.md](./05-DATA-MODEL.md)
- Module docs in `docs/modules/`
- [10-CHANGELOG.md](./10-CHANGELOG.md)
- [09-DECISIONS.md](./09-DECISIONS.md) for architectural decisions

---

## STEP 9 — FINAL REPORT

Always report:

1. What changed and why
2. Files created/modified
3. Tests run and results
4. Documentation updated
5. Remaining TODO items
6. Risks or breaking changes
7. Items needing human approval (**NEEDS-DECISION**)

---

## AI SAFETY RULES

### MUST NOT

- Invent requirements, APIs, tables, permissions, or business rules
- Refactor unrelated working code
- Rename/move files without explicit request
- Install packages without approval
- Modify migrations that ran in production
- Expose secrets or credentials
- Duplicate existing services/components
- Guess when unclear — mark **NEEDS-DECISION**
- Overwrite docs blindly — preserve useful content
- Change application code during documentation-only tasks

### MUST

- Read existing code first
- Follow General/ namespace for shared catalog
- Use module structure for audience-specific features
- Keep docs synchronized with implementation
- Minimize scope of changes
- Match existing naming and patterns

---

## Project Quick Reference

| Item | Value |
|------|-------|
| Laravel | ^12 |
| PHP | ^8.2 |
| Vue | ^3.5 |
| Modules | Admin, User, AI, Provider |
| Admin API | `/api/admin/v1/*` guard `admin_api` |
| User API | `/api/user/v1/*` guard `user_api` |
| Shared catalog | `app/.../General/` |
| Admin SPA | `/admin` → `resources/js/app.js` |
| User SPA | `/user` → `resources/js/user-app.js` |
| API envelope | `App\Support\Api\ApiResponse` |
| Locales | `ar`, `en` |

---

## When Unclear

Stop and mark **NEEDS-DECISION**. Ask the human when the decision affects:

- Business rules
- Permissions/roles
- Public API scope
- Database schema
- Breaking API changes
- New dependencies

---

## Documentation-Only Tasks

When asked to document (not implement):

- Analyze codebase first
- Do not modify application logic
- Mark unknowns honestly
- Do not invent features
