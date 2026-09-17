# Contributing

Official guide for human developers and AI assistants working on Dorr.

---

## Before You Start

1. Read [README.md](../README.md)
2. Read [AI-INSTRUCTIONS.md](./AI-INSTRUCTIONS.md) (especially for AI tools)
3. Read [04-ARCHITECTURE.md](./04-ARCHITECTURE.md) and [16-STYLEGUIDE.md](./16-STYLEGUIDE.md)
4. Check [11-CHECKPOINT.md](./11-CHECKPOINT.md) for current project state

---

## Local Setup

See [14-DEPLOYMENT.md](./14-DEPLOYMENT.md):

```bash
composer setup
composer dev
```

---

## Understanding the Architecture

| Question | Document |
|----------|----------|
| What does the product do? | [01-PRODUCT-REQUIREMENTS.md](./01-PRODUCT-REQUIREMENTS.md) |
| How does it behave? | [02-PRODUCT-SPECIFICATION.md](./02-PRODUCT-SPECIFICATION.md) |
| How is it built? | [03-TECHNICAL-SPECIFICATION.md](./03-TECHNICAL-SPECIFICATION.md) |
| Folder structure? | [04-ARCHITECTURE.md](./04-ARCHITECTURE.md) |
| Database? | [05-DATA-MODEL.md](./05-DATA-MODEL.md) |
| API endpoints? | [06-API-SPECIFICATION.md](./06-API-SPECIFICATION.md) |
| Module details? | [docs/modules/](./modules/) |

---

## Creating a Feature

Follow [07-IMPLEMENTATION-PLAN.md](./07-IMPLEMENTATION-PLAN.md).

Summary:
1. Confirm requirements (no guessing)
2. Inspect similar existing code
3. Plan backend + frontend + tests + docs
4. Implement using existing patterns
5. Run tests + build
6. Update documentation

---

## Creating a Module

1. Match structure of existing modules (`Modules/Admin`, etc.)
2. Register in `modules_statuses.json`
3. Add `RouteServiceProvider` loading `routes/api.php`
4. Create `docs/modules/{name}/` documentation
5. **NEEDS-DECISION:** Frontend module folder if SPA pages needed

---

## Creating an API Endpoint

1. Choose route file:
   - Shared catalog → wire in `Modules/Admin/routes/admin.php` + General classes
   - Module feature → module `routes/admin.php` or `routes/user.php` or `dashboard.php`
2. Add middleware: `locale`, `auth:admin_api` or `auth:user_api`
3. Controller → Service → Repository
4. Form Request + API Resource
5. Document in [06-API-SPECIFICATION.md](./06-API-SPECIFICATION.md)

---

## Creating a Database Change

1. New migration (never edit old production migrations)
2. Module migrations → `Modules/{Module}/database/migrations/`
3. Shared tables → `database/migrations/`
4. Update [05-DATA-MODEL.md](./05-DATA-MODEL.md)
5. Run `php artisan migrate` and test rollback if applicable

---

## Creating Vue Components

### Admin pages
- Views in `resources/js/modules/admin/views/`
- Routes in `resources/js/modules/admin/routes.js`
- Use `adminAxios`, composables, Pinia stores
- PrimeVue components available

### User pages
- Views in `resources/js/modules/user/views/`
- Routes in `resources/js/modules/user/routes.js`
- Use `userAxios`

### Shared components
- Place in `resources/js/components/` if used by both SPAs

---

## Using Pinia

- Stores in `resources/js/stores/`
- Catalog counts: use `createCatalogStore.js` factory
- Auth: `auth.js` (admin), `userAuth.js` (user)

---

## Using Vue Router

- Admin: `resources/js/router/index.js` + `modules/admin/routes.js`
- User: `resources/js/router/user-index.js` + `modules/user/routes.js`
- Middleware: `router/middleware/` + `guards.js` pipeline

---

## Adding Permissions

**Currently not enforced.** When Spatie is activated:

1. Define permissions in seeder
2. Add middleware to routes
3. Implement `usePermission.js`
4. Update [13-SECURITY.md](./13-SECURITY.md)

---

## Writing Tests

See [12-TESTING.md](./12-TESTING.md).

Minimum for new API features:
- Happy path feature test
- Validation failure test
- Auth failure test (if protected)

---

## Updating Documentation

**Required** when changing:
- Architecture
- API endpoints
- Database schema
- Business rules
- Security model

Update:
- Relevant core doc
- Module doc in `docs/modules/`
- [10-CHANGELOG.md](./10-CHANGELOG.md)
- [11-CHECKPOINT.md](./11-CHECKPOINT.md) if milestone changed

---

## Git Workflow

**NEEDS-DECISION:** Branch naming, review process, protected branches.

Suggested:
- Feature branch from main
- Pull request with description + test plan
- No force push to main

---

## Commit Conventions

See [16-STYLEGUIDE.md](./16-STYLEGUIDE.md#git).

```
feat(Admin): add provider export
fix(User): correct OTP expiry handling
refactor(General): extract translation sync
test(AI): add chat status endpoint test
docs: update API specification
chore: update dependencies
```

---

## Pull Request Requirements

- [ ] Focused scope (no unrelated changes)
- [ ] Tests pass (`composer test`)
- [ ] Frontend builds (`npm run build`) if JS changed
- [ ] Pint clean if PHP changed
- [ ] Documentation updated
- [ ] No secrets committed
- [ ] CHANGELOG updated for notable changes

---

## Questions?

Mark unknowns as **NEEDS-DECISION** in docs and ask the team — do not guess business rules or permissions.
