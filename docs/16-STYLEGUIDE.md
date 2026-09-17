# Style Guide

Coding standards for Dorr. Follow existing patterns before introducing new ones.

---

## Laravel / PHP

### Standards
- PSR-4 autoloading
- PSR-12 style (enforce via Laravel Pint)
- Laravel 12 conventions
- Strict types where existing files use them

### Naming

| Type | Convention | Example |
|------|------------|---------|
| Controller | PascalCase + Controller | `CountryController` |
| Service | PascalCase + Service | `CountryService` |
| Repository | PascalCase + Repository | `CountryRepository` |
| Form Request | PascalCase + Request | `CountryRequest` |
| Resource | PascalCase + Resource | `CountryResource` |
| Model | PascalCase singular | `Country` |
| Migration | snake_case table | `create_countries_table` |
| Enum | PascalCase singular | `UserStatus` |
| Trait | Descriptive | `SearchFilterTrait` |

### Controllers

- **Thin controllers** — delegate to services
- Catalog controllers extend `CatalogController`
- General catalog controllers in `App\Http\Controllers\General\`
- Module controllers in `Modules\{Module}\Http\Controllers\`
- Type-hint Form Requests for validation

```php
public function store(CountryRequest $request)
{
    return $this->service->create($request->validated());
}
```

### Models

- Define `$fillable` explicitly
- Use Enums for fixed value sets (cast in `casts()`)
- Relationships with return type hints
- Shared concerns in `app/Models/Concerns/`
- Translation models: `{Entity}Translation`

### Form Requests

- Validation rules in `rules()` method
- Use `match ($this->route()->getActionMethod())` for action-specific rules (catalog pattern)
- Shared catalog rules: `HasCatalogRules` concern
- General requests in `App\Http\Requests\General\`

### API Resources

- Extend `JsonResource`
- Use `FormatsTranslations` concern for translatable entities
- General resources in `App\Http\Resources\General\`

### Services

- Extend `BaseService` or `CatalogService`
- Return `JsonResponse` via `ApiResponse` helpers
- Business logic lives here, not in controllers
- Use hooks: `beforeStore`, `afterUpdate`, etc.

### Repositories

- Extend `BaseRepository` or `TranslatableRepository`
- Database queries here, not in services/controllers
- Eager load via `$with` property
- Delete protection via `$deleteBlockRelations`

### Routes

- API prefix: `/api/admin/v1` or `/api/user/v1`
- Always apply `locale` middleware on API groups
- Use `guest:{guard}` and `auth:{guard}` correctly
- Name routes when needed (admin resource names)

### Migrations

- One concern per migration
- Foreign keys with constrained references
- Translation tables: `{entity}_translations`

### Avoid

- Fat controllers with business logic
- Duplicate CRUD logic outside base classes
- Raw queries without necessity
- Hard-coded locale lists (use `LanguageRepository::storableLocaleCodes()`)
- N+1 queries (use `$with` on repositories)
- Unnecessary packages

---

## API

### Endpoint Naming
- kebab-case plural resources: `/service-categories`
- Actions: `/delete-multiple`, `/{id}/status`, `/dropdown`, `/sync-exchange-rates`

### HTTP Methods
| Action | Method |
|--------|--------|
| List | GET |
| Create | POST |
| Show | GET |
| Update | PUT/PATCH |
| Delete | DELETE |
| Bulk delete | POST `/delete-multiple` |
| Status | PATCH `/{id}/status` |

### Response Structure
Always use `App\Support\Api\ApiResponse`:

```json
{
  "success": true,
  "status": "success",
  "code": 200,
  "message": "...",
  "data": {},
  "pagination": null
}
```

### Errors
- Validation: 422 + `errors` object
- Auth: 401
- Conflict: 409
- Use translated messages via `__()` where applicable

### Pagination
Use `allOrPaginate()` + `ApiPaginator` — do not invent custom pagination shapes.

---

## Vue.js

### Standards
- Vue 3 Composition API
- `<script setup>` preferred for new components
- Pinia for global state
- Vue Router with middleware pipeline
- Composables for reusable logic

### File Organization

```
resources/js/
├── modules/{audience}/views/   # page-level views
├── components/{domain}/        # reusable components
├── composables/                # shared logic
├── stores/                     # Pinia
├── api/                        # axios clients
└── locales/                    # i18n JSON
```

### Components
- Keep focused — extract modals, form sections
- Admin catalog pattern: `index.vue` + `ModalCreateAndUpdate.vue`
- Use `FormFieldFeedback.vue` for validation display
- Use `TableSkeleton.vue` for loading states

### Composables
- `crudStructure.js` for admin list pages
- `useValidation.js` for Vuelidate rules with i18n
- `useToast.js` for notifications
- Do not leave empty stubs without TODO comment (existing stubs: `useAuth`, `usePermission`)

### API Calls
- Admin: `adminAxios` only
- User: `userAxios` only
- Do not use raw `window.axios` for authenticated API calls

### i18n
- All user-facing strings via `$t()` / `t()`
- Add keys to **both** `ar.json` and `en.json`
- Use namespaced keys matching feature area

### Forms (Admin)
- Vuelidate with `useValidation.js` helpers
- `{ $autoDirty: true }` for reactive validation
- Map API 422 errors via `applyApiErrors()`

### Avoid
- Giant monolithic Vue files
- Duplicate CRUD logic outside `crudStructure`
- Hard-coded Arabic/English strings in templates
- Direct DOM manipulation except legacy dashboard scripts (`useDashboard.js`)

---

## Database

| Rule | Standard |
|------|----------|
| Tables | snake_case plural |
| Columns | snake_case |
| FK columns | `{model}_id` |
| Pivot tables | alphabetical singular names |
| Indexes | Add for FKs and frequent filters |
| Translations | `{table}_translations` with `locale` + `name` |

---

## Git

### Commit Format

```
type(scope): description
```

**Types:** `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `style`, `perf`

**Scopes:** `Admin`, `User`, `AI`, `Provider`, `General`, `Frontend`, `Docs`

**Examples:**
```
feat(General): add public country dropdown route
fix(User): handle expired OTP flow token
docs: add AI module API specification
test(Admin): add login feature test
```

### Rules
- One logical change per commit when possible
- Do not commit `.env`, credentials, or `vendor/`
- Update docs in same PR when behavior changes

---

## Documentation

- Markdown in `docs/`
- Use **NEEDS-DECISION** and **TODO** markers honestly
- Do not document unimplemented features as completed
- Keep [06-API-SPECIFICATION.md](./06-API-SPECIFICATION.md) in sync with routes

---

## PHP Tools

```bash
./vendor/bin/pint        # Format PHP
./vendor/bin/pint --test # Check only
composer test            # Run tests
```

---

## Frontend Tools

```bash
npm run dev    # Development
npm run build  # Production build
```

**TODO:** Add ESLint + Prettier when team decides configuration.
