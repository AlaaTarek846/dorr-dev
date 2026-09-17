# Implementation Plan

Standard workflow for implementing major features in Dorr.

---

## Feature Implementation Workflow

Every major feature MUST follow these steps:

### 1. Understand Requirements

- Read [01-PRODUCT-REQUIREMENTS.md](./01-PRODUCT-REQUIREMENTS.md)
- Read relevant module docs in `docs/modules/`
- Confirm scope with stakeholder if unclear — mark **NEEDS-DECISION** rather than guessing

### 2. Read Relevant Documentation

- [04-ARCHITECTURE.md](./04-ARCHITECTURE.md) — system shape
- [05-DATA-MODEL.md](./05-DATA-MODEL.md) — database impact
- [06-API-SPECIFICATION.md](./06-API-SPECIFICATION.md) — existing endpoints
- [16-STYLEGUIDE.md](./16-STYLEGUIDE.md) — coding standards
- [AI-INSTRUCTIONS.md](./AI-INSTRUCTIONS.md) — if using AI assistance

### 3. Inspect Existing Code

- Find similar feature (e.g., existing catalog entity for new catalog entity)
- Trace: Route → Controller → Service → Repository → Model
- Check frontend: composable → store → view pattern

### 4. Identify Affected Areas

| Area | Questions |
|------|-----------|
| Modules | Admin? User? AI? Provider? General? |
| Database | New tables? Alter migrations? Translations? |
| API | New routes under `admin/v1` or `user/v1`? |
| Frontend | Admin SPA, User SPA, or both? |
| Permissions | Spatie roles needed? (**NEEDS-DECISION**) |
| Media | Spatie collections? |
| i18n | New translation keys in ar.json/en.json? |

### 5. Write Implementation Plan (for major work)

Document before coding:
- Files to create/modify
- Migration plan
- API endpoints
- Frontend routes/views
- Test plan

### 6. Implement Backend

**Order:**
1. Migration + Model (+ relationships)
2. Repository (extend `BaseRepository` or `TranslatableRepository`)
3. Service (extend `BaseService` or `CatalogService`)
4. Form Request(s)
5. API Resource(s)
6. Controller
7. Routes (correct module route file + middleware)

**Catalog entity checklist:**
- [ ] `General/{Entity}Repository` with `$with`, `$deleteBlockRelations`
- [ ] `General/{Entity}Service` with `$resource`
- [ ] `General/{Entity}Request` with `HasCatalogRules`
- [ ] `General/{Entity}Resource` with `FormatsTranslations`
- [ ] `General/{Entity}Controller` extending `CatalogController`
- [ ] Routes in `Modules/Admin/routes/admin.php` (and user routes if needed)

### 7. Implement Frontend

**Admin catalog page checklist:**
- [ ] Pinia count store (or reuse `createCatalogStore`)
- [ ] Composable via `useCatalog` or `crudStructure`
- [ ] `modules/admin/views/{entity}/index.vue` + modal
- [ ] Route in `modules/admin/routes.js`
- [ ] Sidebar link in `Sidebar.vue`
- [ ] i18n keys in ar.json + en.json

### 8. Add/Update Validation

- Backend: Form Request rules
- Frontend: Vuelidate via `useValidation.js` (admin forms)

### 9. Add/Update Permissions

**Currently optional** — Spatie not wired to routes.

When enabled:
- Define permission names
- Seed roles
- Add middleware to routes
- Implement `usePermission.js`

### 10. Add Tests

- Feature test for API endpoints (auth, validation, success)
- Extend `ApiExceptionRendererTest` patterns for new error cases
- Run `php artisan test`

### 11. Run Checks

```bash
composer test
./vendor/bin/pint --test   # if using Pint
npm run build              # verify frontend builds
```

### 12. Review Changes

- Architecture alignment
- No duplicate logic
- No unrelated changes
- Security review for auth/upload endpoints

### 13. Update Documentation

Update relevant docs:
- API changes → `06-API-SPECIFICATION.md` + module `API.md`
- Schema changes → `05-DATA-MODEL.md` + module `DATA-MODEL.md`
- New decisions → `09-DECISIONS.md`
- Release notes → `10-CHANGELOG.md`
- Status → `11-CHECKPOINT.md`

---

## New Module Checklist

When adding a new nwidart module:

1. `php artisan module:make {Name}` (or manual scaffold matching existing modules)
2. Register in `modules_statuses.json`
3. Create `routes/admin.php`, `routes/user.php`, or `routes/dashboard.php` as needed
4. `RouteServiceProvider` matching existing modules
5. Add `docs/modules/{name}/` documentation
6. **NEEDS-DECISION:** Frontend module folder if SPA pages required

---

## Database Change Rules

1. Never modify old migrations that ran in production — create new migrations
2. Module migrations stay in `Modules/{Module}/database/migrations/`
3. Shared catalog migrations in `database/migrations/`
4. Document FK relationships in `05-DATA-MODEL.md`

---

## Breaking Change Protocol

1. Document in `10-CHANGELOG.md` under **Breaking Changes**
2. Add ADR in `09-DECISIONS.md` if architectural
3. Coordinate frontend + backend deployment
4. Add migration rollback notes in `14-DEPLOYMENT.md`
