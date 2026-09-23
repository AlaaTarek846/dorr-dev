# Admin — Data Model

## Table: `admins`

Created: `Modules/Admin/database/migrations/2026_09_14_075327_create_admins_table.php`

Key columns (see migration for full schema):
- id, name, email, password
- status
- country_id → countries
- timestamps

## Table: `admin_services`

Created: `Modules/Admin/database/migrations/2026_09_21_100000_create_admin_services_table.php`

- `admin_id` → `admins.id` (cascade on delete)
- `service_category_id` → `service_categories.id` (cascade on delete)
- Unique pair `(admin_id, service_category_id)`

Assigns which service categories an admin (employee) may operate on.

## Relationships

- `belongsTo` Country
- `hasMany` `AdminService` as `services` → service categories
- Referenced by platform (delete blocks country removal)

## Media

- Avatar collection via Spatie Media Library

## Sanctum

- Tokens in `personal_access_tokens` with admin tokenable type
