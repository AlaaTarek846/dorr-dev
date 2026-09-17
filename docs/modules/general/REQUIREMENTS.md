# General — Requirements

## Functional Requirements (Implemented)

### Catalog CRUD (Admin)
- List with pagination, search, status filter
- Create/update with multilingual translations
- Single delete + bulk delete
- Status toggle
- Dropdown endpoints (except languages — public dropdown)

### Platform Settings
- Branding endpoint (unauthenticated within admin API group)
- Admin update: app name + media assets (logos, favicons)

### Service Categories
- Tree view
- Leaf options for provider linking
- Image upload per category
- Hierarchical parent/child

### Currencies
- Exchange rate sync (Open ER API)
- Auto-sync on list/view when stale

### Languages
- Control which locales store translations
- Default dashboard/website language flags
- Purge catalog translations when locale disabled

### User Access
- Countries dropdown (authenticated user)

## Non-Functional

- All responses via `ApiResponse` envelope
- Translations validated against storable language locales
- Delete protection when related records exist

## TODO / NEEDS-DECISION

- [ ] Public read-only API for website (**NEEDS-DECISION**)
- [ ] Public-specific API Resources with reduced fields (**NEEDS-DECISION**)
- [ ] User-facing service category tree (**NEEDS-DECISION**)
